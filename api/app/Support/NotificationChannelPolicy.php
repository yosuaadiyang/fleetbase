<?php

namespace App\Support;

use Illuminate\Notifications\Events\NotificationSending;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/** Skip optional mobile transports before Laravel constructs their SDK clients. */
final class NotificationChannelPolicy
{
    public static function handle(NotificationSending $event): ?bool
    {
        $route = match ($event->channel) {
            FcmChannel::class, 'fcm' => 'fcm',
            ApnChannel::class, 'apn' => 'apn',
            default => null,
        };
        // Null preserves later listeners, including other authorization vetoes.
        if ($route === null) return null;
        if (config('contrust.mobile_push_enabled', false) !== true) return false;
        $tokens = (array) $event->notifiable->routeNotificationFor($route, $event->notification);
        return array_filter($tokens, fn ($token) => is_string($token) && trim($token) !== '') ? null : false;
    }
}
