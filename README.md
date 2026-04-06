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
- Optional cron to backfill missing Discord items for the selected day
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
5. Set the weekly summary and daily event channel IDs
6. Run the bootstrap:

```bash
php setup/db_bootstrap.php
```

7. Point your web root at the `public` folder

## Notes

- The public schedule page stays visible without login.
- A user is allowed in only if their logged-in Discord account holds one of the configured roles in `DISCORD_GUILD_ID`.
- Recommended OAuth scopes: `identify guilds.members.read`
- All event times are entered in the clan timezone and stored in UTC.
- Discord local time rendering is handled through Discord timestamps.
- Weekly recurring events now require a recurring until date.
- Full recurring series management uses `recurring_series_id`.
- Day-of publishing creates the actual native Discord event only on the event date, which avoids cluttering the server with too many future scheduled events.

## Discord Publishing

Open `post_schedule.php` in the web app to:

- post or update the weekly summary
- run the Discord sync manually
- copy the server cron commands

### Cron scripts

The app includes:

- `cron/cron_weekly_summary.php`
- `cron/cron_sync_discord.php`

These are intended to be run directly on the server, for example:

```bash
php /full/path/to/cron/cron_weekly_summary.php
php /full/path/to/cron/cron_sync_discord.php
```

Both scripts also accept an optional date argument:

```bash
php /full/path/to/cron/cron_weekly_summary.php 2026-04-07
php /full/path/to/cron/cron_sync_discord.php 2026-04-07
```

## Suggested cron usage

- Weekly summary: once per week on your preferred day and time
- Discord sync: once per day shortly after midnight clan time

## This patch adds

- `discord_weekly_posts` table
- Discord sync columns on `clan_events` for:
  - daily message tracking
  - native scheduled event tracking
- manual Discord Publishing admin page
- server-side cron scripts with no public token requirement


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
