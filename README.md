# Clan Event Scheduler

A lightweight PHP web app for managing a weekly clan event schedule and posting clan events to Discord.

## Features

- Single shared database across multiple clan instances
- Clan scoping via `.env` `CLAN_ID`
- Branding via `.env`
- Multiple events per date
- Weekly grouped schedule view
- Event create, edit, delete
- Weekly recurring event management
- Discord OAuth login
- Role-based access control using Discord guild roles
- Weekly summary Discord post
- Day-of daily event embeds
- Day-of native Discord scheduled events using the external event type
- Manual Sync / Cancel actions that preserve existing .env Discord channel settings
- Optional cron to backfill missing Discord items for today only
- Idempotent database bootstrap script that creates and updates tables

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- PDO MySQL extension
- cURL extension
- Discord bot token with permission to send messages in the target channels
- Discord bot permission to create guild scheduled events
- A Discord OAuth application configured with a matching redirect URI

## Setup

1. Copy `.env.example` to `.env`
2. Update the database, clan, branding, bot, and Discord OAuth values
3. Set `DISCORD_GUILD_ID` to the server for this clan instance
4. Set `ADMIN_ROLE_IDS` to one or more role IDs that should be allowed into the app
5. Optionally set `ADMIN_USER_IDS` to one or more Discord user IDs that should always be allowed into the app
6. Set the weekly summary and daily event channel IDs
7. Run the bootstrap:

## Notes

- The public schedule page stays visible without login.
- A user is allowed in if their logged-in Discord account holds one of the configured roles in `DISCORD_GUILD_ID`, or their user ID is listed in `ADMIN_USER_IDS`.
- Recommended OAuth scopes: `identify guilds.members.read`
- All event times are entered in the clan timezone and stored in UTC.
- Discord local time rendering is handled through Discord timestamps.
- Weekly recurring events now require a recurring until date.
- Full recurring series management uses `recurring_series_id`.
- Day-of publishing creates the actual native Discord event only on the event date, which avoids cluttering the server with too many future scheduled events.

## Discord Publishing

Open `post_schedule.php` in the web app to:

- post or update the weekly summary
- run the day-of event publisher manually
- copy the cron URLs

### Cron scripts

The Discord sync cron now handles the weekly summary as well as daily Discord sync work.

Use:

- `cron_sync_discord.php`

Keep `cron_weekly_summary.php` only for manual/backwards-compatible one-off runs. It no longer needs to be scheduled separately.

Example server cron, run at clan-local midnight each day:

```text
0 0 * * * /usr/local/bin/php /path/to/events/cron/cron_sync_discord.php >> /path/to/events/logs/discord_sync.log 2>&1
```

The weekly summary is created or updated only when this sync runs at **00:00 on Monday morning in the clan timezone**. Existing weekly summary posts can still be refreshed by normal sync activity when related events or host names change.

## Suggested cron usage

- Discord sync: once per day at midnight in the clan timezone
- Weekly summary: do not schedule separately; handled by `cron_sync_discord.php` on Monday at 00:00

## This patch adds

- `discord_weekly_posts` table
- Discord sync columns on `clan_events` for:
  - daily message tracking
  - native scheduled event tracking
- manual Discord Publishing admin page
- token-protected cron endpoints


## Location field

Events now support an optional `Location` value. If left blank, Discord native scheduled events continue to fall back to `DISCORD_EVENT_LOCATION_DEFAULT` from `.env`.

The weekly summary flow now also recovers automatically if the previously stored Discord message was deleted, by posting a fresh summary instead of failing with `Unknown Message (10008)`.


## Discord channel override

`discord_channel_id` now acts only as an optional **Discord Channel Override** for daily event posts. It is blank by default and is **not prefilled**. When left blank, the app uses `DISCORD_DAILY_EVENT_CHANNEL_ID` from `.env`.

The weekly summary always uses `DISCORD_WEEKLY_SUMMARY_CHANNEL_ID` from `.env`.


## Automatic bootstrap

The app now loads the database bootstrap on every request. The bootstrap function checks that the schema is correct and only applies missing changes, so it is safe to leave enabled.


## Database cleanup

The legacy `discord_event_posts` table is no longer used and is dropped by the bootstrap, per your request.
