# Optional mobile push

The stock order notifications always list FCM and APNs. Laravel constructs the
FCM SDK before its `send()` method can notice that a driver has no device token.
An unconfigured Firebase project therefore fails a queue job even with no push
recipient. This was reproduced during the approved demo preflight.

`NotificationChannelPolicy` vetoes optional push before driver resolution unless
`CONTRUST_MOBILE_PUSH_ENABLED=true` is explicitly configured. Even when enabled,
empty/invalid device routes are skipped. Mail and broadcast retain their normal
behavior, retries and failure handling. The listener returns null when allowing
other channels so that later listeners can still enforce their own vetoes.

The internal v1 pilot uses email and in-app order delivery. Native background
push is not certified. Enable it only after supplying a compatible FCM/APNs
provider configuration and obtaining a device token for the installed app;
recreate API/queue/scheduler after changing the environment. No provider account,
credential, billing or external message is created by this patch.

Eight required offline checks run the real Laravel notification sender, event
dispatcher and channel manager against fake recording transports. In particular,
they prove unavailable SDK clients are never constructed and other listeners'
denials are preserved. They send no email or push and require no database.
