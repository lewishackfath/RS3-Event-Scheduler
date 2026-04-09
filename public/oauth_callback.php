<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $state = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state']);

    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Invalid OAuth state. Please try logging in again.');
    }

    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Discord did not return an authorisation code.');
    }

    $token = exchangeDiscordCodeForToken($code);
    $accessToken = (string) ($token['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Discord did not return an access token.');
    }

    $discordUser = fetchDiscordUser($accessToken);
    $member = fetchDiscordCurrentUserGuildMember($accessToken, authGuildId());

    if (!userCanAccessApp($discordUser, $member)) {
        logoutUser();
        throw new RuntimeException('Your Discord account does not have one of the required admin roles or user overrides for this app.');
    }

    setAuthenticatedUser($discordUser, $member);
    setFlash('success', 'Logged in successfully via Discord.');
    redirect(consumeReturnTo());
} catch (Throwable $e) {
    setFlash('error', $e->getMessage());
    redirect('login.php');
}
