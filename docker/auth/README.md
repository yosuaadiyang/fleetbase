# Authentication validation patch

The upstream LoginRequest, SignUpRequest and TwoFaValidationRequest return a JSON
response from failedValidation(), but Laravel ignores its return value and
continues resolving the request. Invalid input can reach the controller.

These overrides throw HttpResponseException with the existing JSON errors/422
response. Login identity/password, signup names/confirmation and 2FA token also
reject non-string values. They preserve generic credential errors and the
existing email/phone login choices.

Build on the VPS from the docker/ context:

```sh
docker build -f docker/auth/Dockerfile -t contrust-api:auth-audit-20261014 docker
```

The base image is pinned. SHA-256 checks reject different vendor versions instead
of silently applying incompatible files. The routing patch is retained. Build
tests exercise Laravel's actual FormRequest validation lifecycle without booting
the application, sending mail or querying a database. Validation success cases
do not authenticate or create users. The test's presence verifier is synthetic;
it does not verify production account lookup or permissions.

Local validation uses Illuminate 10.49 components and the FormRequest source at
the commit pinned by api/composer.lock. The original request classes fail ten
negative cases; the patched classes pass all twelve checks. PHP 8.2 image/build
validation and deployment remain required before marking this patch active.

Deployment must preserve existing environment, routing and volumes, and restart
only application/queue/scheduler with a graceful timeout. Verify API health,
invalid login HTTP 422, valid human login and real reset delivery afterward.
Publish the corresponding source archive alongside the running derived image.
