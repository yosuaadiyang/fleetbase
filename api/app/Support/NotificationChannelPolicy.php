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
        // This Android release does not support APNs or unbound legacy devices.
        if ($route !== 'fcm' || !$event->notifiable instanceof \Fleetbase\Models\User) return false;
        return DriverPushDevices::eligible($event->notifiable) ? null : false;
    }
}
