<?php
// Actual Laravel sender/event/driver resolution. No database or external message.
require '/fleetbase/api/vendor/autoload.php';
use App\Support\NotificationChannelPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\{AnonymousNotifiable, ChannelManager, Notification, NotificationSender};
use Illuminate\Notifications\Events\NotificationSending;
use NotificationChannels\{Apn\ApnChannel, Fcm\FcmChannel};
$app = new Container(); Container::setInstance($app);
$config = new Repository(['app' => ['locale' => 'en'], 'contrust' => ['mobile_push_enabled' => false]]);
$app->instance('config', $config);
$events = new Dispatcher($app);
$events->listen(NotificationSending::class, [NotificationChannelPolicy::class, 'handle']);
$manager = new ChannelManager($app); $sent = []; $constructed = [];
foreach (['mail', 'broadcast', FcmChannel::class, ApnChannel::class] as $name) {
    $manager->extend($name, function () use ($name, &$constructed, &$sent) {
        $constructed[] = $name;
        return new class($name, $sent) {
            private $sent;
            public function __construct(private string $name, array &$sent) { $this->sent = &$sent; }
            public function send($recipient, $notification) { $this->sent[] = $this->name; }
        };
    });
}
$sender = new NotificationSender($manager, new Illuminate\Bus\Dispatcher($app), $events);
$notification = new class extends Notification {
    public function via($notifiable) { return ['mail', 'broadcast', FcmChannel::class, ApnChannel::class]; }
};
$checks = 0;
function channelCheck($ok, $name) { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; echo 'PASS optional notification channels: ' . $name . PHP_EOL; }
$recipient = (new AnonymousNotifiable())->route('fcm', ['synthetic-android-token'])->route('apn', ['synthetic-ios-token']);
$sender->sendNow($recipient, $notification);
channelCheck($sent === ['mail', 'broadcast'], 'disabled push preserves email and in-app dispatch');
channelCheck(!in_array(FcmChannel::class, $constructed) && !in_array(ApnChannel::class, $constructed), 'disabled SDK clients are never constructed');
channelCheck(NotificationChannelPolicy::handle(new NotificationSending($recipient, $notification, 'mail')) === null, 'other event listeners may still veto mail');
$config->set('contrust.mobile_push_enabled', true); $sent = [];
$empty = (new AnonymousNotifiable())->route('fcm', [null, '', ' '])->route('apn', []);
$sender->sendNow($empty, $notification);
channelCheck($sent === ['mail', 'broadcast'], 'enabled push without valid device routes is skipped');
channelCheck(!in_array(FcmChannel::class, $constructed) && !in_array(ApnChannel::class, $constructed), 'missing token does not resolve missing Firebase configuration');
$sent = [];
$sender->sendNow($recipient, $notification);
channelCheck($sent === ['mail', 'broadcast', FcmChannel::class, ApnChannel::class], 'explicitly enabled configured channels remain available');
$events->listen(NotificationSending::class, fn ($event) => $event->channel === 'mail' ? false : null);
$sent = [];
$sender->sendNow($recipient, $notification);
channelCheck(!in_array('mail', $sent), 'policy does not bypass a later listener denial');
channelCheck(in_array('broadcast', $sent), 'later mail denial does not suppress in-app notifications');
echo $checks . ' optional notification channel checks passed; fake drivers only, no external email or push.' . PHP_EOL;
