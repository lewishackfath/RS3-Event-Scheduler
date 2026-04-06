<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flashMessage(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $message = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $message;
}

function setFlash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function currentClanId(): int
{
    return appConfig()['clan']['id'];
}

function currentClanName(): string
{
    return appConfig()['clan']['name'];
}

function branding(): array
{
    return appConfig()['branding'];
}

function eventDisplayImageUrl(array $event): string
{
    $imageUrl = trim((string) ($event['image_url'] ?? ''));
    if ($imageUrl !== '') {
        return $imageUrl;
    }

    return trim((string) (branding()['header_image_url'] ?? ''));
}
