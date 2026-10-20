<?php

namespace App\Support;

use Fleetbase\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;

/** No names, chat contents, locations or work IDs cross the push transport. */
final class PrivateFcmChannel
{
    public function __construct(private Messaging $client) {}

    public static function payload(): array
    {
        return ['notification' => ['title' => 'Contrust', 'body' => 'Ada pembaruan operasional. Buka aplikasi untuk melihatnya.'],
            'data' => ['type' => 'contrust_update'],
            'android' => ['ttl' => '60s', 'collapse_key' => 'contrust_update',
                'notification' => ['tag' => 'contrust_update']]];
    }

    public function send(mixed $notifiable, Notification $notification): ?Collection
    {
        if (config('contrust.mobile_push_enabled', false) !== true || !$notifiable instanceof User) return null;
        $sent = collect();
        foreach (DriverPushDevices::eligible($notifiable) as $token) {
            try {
                $this->client->send(CloudMessage::fromArray(self::payload())->withChangedTarget('token', $token));
                $sent->push(true);
            } catch (NotFound $exception) {
                // Unregistered tokens stop receiving retries; never log their value.
                DriverPushDevices::forgetToken($token);
            } catch (\Throwable $exception) {
                // SDK messages can include device tokens. Queue errors must not.
                throw new \RuntimeException('Contrust push delivery temporarily unavailable.');
            }
        }
        return $sent;
    }
}
