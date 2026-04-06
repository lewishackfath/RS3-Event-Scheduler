<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/discord.php';
require_once __DIR__ . '/../lib/event_embeds.php';
require_once __DIR__ . '/../repositories/EventRepository.php';

final class DiscordPostingService
{
    private EventRepository $events;

    public function __construct()
    {
        $this->events = new EventRepository();
    }

    public function postEvents(array $events): array
    {
        $results = [];

        foreach ($events as $event) {
            $channelId = trim((string) ($event['discord_channel_id'] ?? ''));
            if ($channelId === '') {
                $results[] = [
                    'event_name' => $event['event_name'],
                    'status' => 'skipped',
                    'message' => 'No Discord channel configured.',
                ];
                continue;
            }

            $embed = buildEventEmbed($event);
            $response = postDiscordMessage($channelId, '', [$embed]);
            $messageId = (string) ($response['id'] ?? '');
            if ($messageId !== '') {
                $this->events->recordDiscordPost((int) $event['id'], $channelId, $messageId);
            }

            $results[] = [
                'event_name' => $event['event_name'],
                'status' => 'posted',
                'message' => 'Posted successfully.',
            ];
        }

        return $results;
    }
}
