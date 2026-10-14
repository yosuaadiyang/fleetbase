FROM contrust-api:auth-audit-20261014
RUN echo '77ee2b924bbc059808d2b6849c4bbfc47f601aeec5b4cf4814904422a2005ef5  /fleetbase/api/app/Providers/AppServiceProvider.php' | sha256sum --check
COPY api/app/Providers/AppServiceProvider.php /fleetbase/api/app/Providers/AppServiceProvider.php
COPY docker/brevo/vendor/BrevoApiTransport.php /fleetbase/api/app/Mail/BrevoApiTransport.php
COPY docker/brevo/vendor/LICENSE /fleetbase/api/app/Mail/BREVO-BRIDGE-LICENSE
COPY docker/brevo/tests/ /tmp/contrust-brevo-tests/
RUN php -l /fleetbase/api/app/Providers/AppServiceProvider.php \
    && php -l /fleetbase/api/app/Mail/BrevoApiTransport.php \
    && php /tmp/contrust-brevo-tests/brevo-transport-test.php \
    && rm -rf /tmp/contrust-brevo-tests
