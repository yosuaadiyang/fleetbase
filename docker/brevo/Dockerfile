# Build from the original digest; retain the independently verified routing fix.
FROM fleetbase/fleetbase-api:v0.7.68@sha256:dc42bcfeadf22e4579dd0a2e68fa95ad9d1ed49765c6f16a469abe6ca3f1fe37
RUN echo '70d794cabbb4076fdb1df4755a62c1c323b60ed37bc023b915c97e4bcf7012be  /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Support/OSRM.php' | sha256sum --check \
    && echo 'c0c452093d7747af8cceea6cccdd971cd63c3a72c2436264b484d37f320f07c8  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/LoginRequest.php' | sha256sum --check \
    && echo '2c93220e757c4f0c49c6715bd1586082676ab53a1cc067b38ef11dc6ee2eda0d  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/SignUpRequest.php' | sha256sum --check \
    && echo 'd095f9e707db5df69a6872a9494320d50d586fe12b83380aa136f67aa8b952d7  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/TwoFaValidationRequest.php' | sha256sum --check
COPY docker/routing/api/OSRM.php /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Support/OSRM.php
RUN echo '4ca14e767c2155b4d848ce3b70cb9afe65870e432c2623535985fa34b1f971ac  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Controllers/Internal/v1/AuthController.php' | sha256sum --check
COPY docker/auth/controllers/AuthController.php /fleetbase/api/vendor/fleetbase/core-api/src/Http/Controllers/Internal/v1/AuthController.php
RUN echo '612d8893b975f6a4d63dc2ebe56a8851e94bde7a27a625889866cf0372f0746f  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Middleware/AuthorizationGuard.php' | sha256sum --check
COPY docker/auth/middleware/AuthorizationGuard.php /fleetbase/api/vendor/fleetbase/core-api/src/Http/Middleware/AuthorizationGuard.php
RUN echo 'b477d4b0d76f74f05e52821b2ace637745f0e8f89daf7181748a987b4e5d082d  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Middleware/AuthenticateOnceWithBasicAuth.php' | sha256sum --check
COPY docker/auth/middleware/AuthenticateOnceWithBasicAuth.php /fleetbase/api/vendor/fleetbase/core-api/src/Http/Middleware/AuthenticateOnceWithBasicAuth.php
COPY api/app/Support/DriverAuthentication.php /fleetbase/api/app/Support/DriverAuthentication.php
COPY docker/auth/patch-driver-auth.php /tmp/contrust-patch-driver-auth.php
RUN php /tmp/contrust-patch-driver-auth.php \
    && php -l /fleetbase/api/app/Support/DriverAuthentication.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/DriverController.php \
    && rm /tmp/contrust-patch-driver-auth.php
COPY api/app/Support/ManagedDriverApiAccess.php /fleetbase/api/app/Support/ManagedDriverApiAccess.php
COPY api/app/Support/ProofImageInput.php /fleetbase/api/app/Support/ProofImageInput.php
COPY docker/auth/patch-driver-order.php /tmp/contrust-patch-driver-order.php
RUN php /tmp/contrust-patch-driver-order.php \
    && php -l /fleetbase/api/app/Support/ManagedDriverApiAccess.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/OrderController.php \
    && rm /tmp/contrust-patch-driver-order.php
COPY docker/auth/api/ /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/
RUN echo '34acc7b56470eb769d5433fbfa33122e969c1674e46e7f42ac180ebecc7b8e3f  /fleetbase/api/vendor/fleetbase/core-api/src/Services/SmsService.php' | sha256sum --check \
    && echo 'a35929e148a8da20e0535f82dbf1c7b48588460e1868052ca85104c482f321e1  /fleetbase/api/vendor/fleetbase/core-api/src/Models/VerificationCode.php' | sha256sum --check
COPY docker/auth/vendor/SmsService.php /fleetbase/api/vendor/fleetbase/core-api/src/Services/SmsService.php
COPY docker/auth/vendor/VerificationCode.php /fleetbase/api/vendor/fleetbase/core-api/src/Models/VerificationCode.php
COPY docker/places/patch-place-api.php /tmp/contrust-patch-place-api.php
RUN php /tmp/contrust-patch-place-api.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/PlaceController.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Requests/CreatePlaceRequest.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Rules/ResolvablePoint.php \
    && rm /tmp/contrust-patch-place-api.php
COPY docker/auth/tests/ /tmp/contrust-auth-tests/
RUN php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/LoginRequest.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/SignUpRequest.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/TwoFaValidationRequest.php \
    && php /tmp/contrust-auth-tests/request-validation-test.php \
    && php /tmp/contrust-auth-tests/token-login-test.php \
    && php /tmp/contrust-auth-tests/api-token-lifetime-test.php \
    && php /tmp/contrust-auth-tests/proof-image-test.php \
    && php /tmp/contrust-auth-tests/sms-log-privacy-test.php \
    && rm -rf /tmp/contrust-auth-tests

RUN echo '77ee2b924bbc059808d2b6849c4bbfc47f601aeec5b4cf4814904422a2005ef5  /fleetbase/api/app/Providers/AppServiceProvider.php' | sha256sum --check
COPY api/app/Support/ProofFileAccess.php /fleetbase/api/app/Support/ProofFileAccess.php
COPY api/app/Support/NotificationChannelPolicy.php /fleetbase/api/app/Support/NotificationChannelPolicy.php
COPY api/app/Providers/AppServiceProvider.php /fleetbase/api/app/Providers/AppServiceProvider.php
COPY docker/brevo/vendor/BrevoApiTransport.php /fleetbase/api/app/Mail/BrevoApiTransport.php
COPY docker/brevo/vendor/LICENSE /fleetbase/api/app/Mail/BREVO-BRIDGE-LICENSE
COPY docker/brevo/tests/ /tmp/contrust-brevo-tests/
RUN php -l /fleetbase/api/app/Providers/AppServiceProvider.php \
    && php -l /fleetbase/api/app/Mail/BrevoApiTransport.php \
    && php /tmp/contrust-brevo-tests/brevo-transport-test.php \
    && rm -rf /tmp/contrust-brevo-tests

RUN echo '58755a90f0edb0a784b0b489e08724f6986500d494d851a9e92f44522ddc01dd  /fleetbase/api/app/Http/Kernel.php' | sha256sum --check
COPY api/app/Http/Kernel.php /fleetbase/api/app/Http/Kernel.php
COPY api/app/Http/Middleware/PublicOrderTracking.php /fleetbase/api/app/Http/Middleware/PublicOrderTracking.php
COPY api/app/Support/PublicOrderTrackingPayload.php /fleetbase/api/app/Support/PublicOrderTrackingPayload.php
COPY docker/tracking/tests/ /tmp/contrust-tracking-tests/
RUN php -l /fleetbase/api/app/Http/Kernel.php \
    && php /tmp/contrust-tracking-tests/public-tracking-test.php \
    && rm -rf /tmp/contrust-tracking-tests

RUN echo '1b8e254e9725d6694aecc2e2d47b8c187b4e797c5290b060eb15c518d5b64cfa  /fleetbase/api/vendor/fleetbase/core-api/src/Support/SocketCluster/SocketClusterService.php' | sha256sum --check \
    && echo '836351342e2d3ef8e4bc27af9f46bb8f731cd2bc9f9788764bb5ff94fe6664a3  /fleetbase/api/app/Providers/RouteServiceProvider.php' | sha256sum --check
COPY docker/socket/api/SocketClusterService.php /fleetbase/api/vendor/fleetbase/core-api/src/Support/SocketCluster/SocketClusterService.php
COPY api/app/Providers/RouteServiceProvider.php /fleetbase/api/app/Providers/RouteServiceProvider.php
COPY api/app/Support/SocketAccessTickets.php api/app/Support/SocketChannelAccess.php /fleetbase/api/app/Support/
COPY api/app/Http/Controllers/SocketAuthorizationController.php /fleetbase/api/app/Http/Controllers/SocketAuthorizationController.php
COPY api/app/Http/Middleware/SocketSessionCookie.php /fleetbase/api/app/Http/Middleware/SocketSessionCookie.php
RUN php -l /fleetbase/api/app/Support/SocketAccessTickets.php \
    && php -l /fleetbase/api/app/Support/SocketChannelAccess.php \
    && php -l /fleetbase/api/app/Http/Controllers/SocketAuthorizationController.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Support/SocketCluster/SocketClusterService.php

COPY api/app/Http/Controllers/ProofFileController.php /fleetbase/api/app/Http/Controllers/ProofFileController.php
COPY docker/files/patch-proof-files.php /tmp/contrust-patch-proof-files.php
RUN php /tmp/contrust-patch-proof-files.php \
    && php -l /fleetbase/api/app/Support/ProofFileAccess.php \
    && php -l /fleetbase/api/app/Http/Controllers/ProofFileController.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Models/File.php \
    && php -l /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/OrderController.php \
    && rm /tmp/contrust-patch-proof-files.php

COPY docker/notifications/tests/ /tmp/contrust-notification-tests/
RUN php /tmp/contrust-notification-tests/channel-policy-test.php && rm -rf /tmp/contrust-notification-tests
