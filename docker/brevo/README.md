# Brevo HTTPS mail transport

Contrust's VPS reaches Brevo HTTPS but SMTP TLS probes on ports 587, 2525 and
465 time out (including TLS 1.2). This integration uses the official Symfony
Brevo Mailer bridge v6.4.41, commit
19167ab50234f5e0a106130052f1db959081d476, with its MIT license. The two vendor
files are unmodified. Their SHA-256 values are:

- BrevoApiTransport.php: ded234aebb3371fb734c41953e4b7bb018fe85ff8a1cf10bfe2b93ea50df1ef8
- LICENSE: c36501896253c617f4216729d951d71fade31f899bb19d0b56519903e3711ffc

AppServiceProvider registers the bridge with Laravel's Mail manager. API,
queue and scheduler use the same integration. The default mailer remains
unchanged until an operator configures the three database settings:
system.mail.mailer, system.mail.from and system.mail.mailers.brevo.
The latter contains the API key and must not be published. Provider setup
uses HTTPS with certificate verification, redirects disabled, 15-second idle
timeout and 20-second total timeout. Transport string contains no key.

Build auth candidate first using docker/auth/Dockerfile. Build Brevo candidate
from repository root with:

```sh
docker build --network=none -f docker/brevo/Dockerfile -t contrust-api:brevo-audit-20261014 .
```

The auth candidate is built from the pinned production base and retains the
routing fix. Check its image identity before this build. The Brevo build
rejects a different AppServiceProvider checksum and exercises real Symfony
MIME/envelope/attachments with mocked HTTP responses. 401/403/429/500 are
failures, never delivery success. Tests make no network calls or emails.

Activation requires a Brevo API key (not SMTP key), active transactional
service and verified sender. The interactive setup preflights key/account/
sender before any DB mutation, backs up only the three affected settings to
root-only storage and tries rollback if activation fails. It sends no email.
One authorized reset test must subsequently be verified through provider
acceptance and the recipient's inbox; neither a mocked test nor a 201 response
alone proves inbox delivery. This does not change billing or buy credits.

The existing admin mail-provider selector does not configure this custom
transport; use the dedicated operator setup until that UI is extended and
verified in an authenticated session.
