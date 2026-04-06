# Clan Event Scheduler

A lightweight PHP web app for managing a weekly clan event schedule and manually posting event embeds to Discord.

## Features

- Single shared database across multiple clan instances
- Clan scoping via `.env` `CLAN_ID`
- Branding via `.env`
- Multiple events per date
- Weekly grouped schedule view
- Event create, edit, delete
- Manual Discord posting with one embed per event
- Discord OAuth login
- Role-based access control using Discord guild roles
- Idempotent database bootstrap script that creates and updates tables

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- PDO MySQL extension
- cURL extension
- Discord bot token with permission to send messages in the target channel
- A Discord OAuth application configured with a matching redirect URI

## Setup

1. Copy `.env.example` to `.env`
2. Update the database, clan, branding, bot, and Discord OAuth values
3. Set `DISCORD_GUILD_ID` to the server for this clan instance
4. Set `ADMIN_ROLE_IDS` to one or more role IDs that should be allowed into the app
5. Run the bootstrap:

```bash
php setup/db_bootstrap.php
```

6. Point your web root at the `public` folder

## Notes

- This build protects the scheduler behind Discord login.
- A user is allowed in only if their logged-in Discord account holds one of the configured roles in `DISCORD_GUILD_ID`.
- Recommended OAuth scopes: `identify guilds.members.read`
- All event times are entered in the clan timezone and stored in UTC.
- Discord local time rendering is handled through Discord timestamps.

## Posting to Discord

Open `post_schedule.php` in the web app and post a selected week. The app sends one embed per event in chronological order.
