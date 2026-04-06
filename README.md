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
- Idempotent database bootstrap script that creates and updates tables

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- PDO MySQL extension
- Discord bot token with permission to send messages in the target channel

## Setup

1. Copy `.env.example` to `.env`
2. Update the database, clan, branding, and Discord values
3. Run the bootstrap:

```bash
php setup/db_bootstrap.php
```

4. Point your web root at the `public` folder

## Notes

- This starter build uses simple sessionless pages and does not include authentication yet.
- All event times are entered in the clan timezone and stored in UTC.
- Discord local time rendering is handled through Discord timestamps.

## Posting to Discord

Open `post_schedule.php` in the web app and post a selected week. The app sends one embed per event in chronological order.
