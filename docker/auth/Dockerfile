# Build from the original digest; retain the independently verified routing fix.
FROM fleetbase/fleetbase-api:v0.7.68@sha256:dc42bcfeadf22e4579dd0a2e68fa95ad9d1ed49765c6f16a469abe6ca3f1fe37
RUN echo '70d794cabbb4076fdb1df4755a62c1c323b60ed37bc023b915c97e4bcf7012be  /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Support/OSRM.php' | sha256sum --check \
    && echo 'c0c452093d7747af8cceea6cccdd971cd63c3a72c2436264b484d37f320f07c8  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/LoginRequest.php' | sha256sum --check \
    && echo '2c93220e757c4f0c49c6715bd1586082676ab53a1cc067b38ef11dc6ee2eda0d  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/SignUpRequest.php' | sha256sum --check \
    && echo 'd095f9e707db5df69a6872a9494320d50d586fe12b83380aa136f67aa8b952d7  /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/TwoFaValidationRequest.php' | sha256sum --check
COPY routing/api/OSRM.php /fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Support/OSRM.php
COPY auth/api/ /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/
COPY auth/tests/ /tmp/contrust-auth-tests/
RUN php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/LoginRequest.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/SignUpRequest.php \
    && php -l /fleetbase/api/vendor/fleetbase/core-api/src/Http/Requests/TwoFaValidationRequest.php \
    && php /tmp/contrust-auth-tests/request-validation-test.php \
    && rm -rf /tmp/contrust-auth-tests
