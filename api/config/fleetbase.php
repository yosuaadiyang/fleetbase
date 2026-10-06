<?php

/*
|--------------------------------------------------------------------------
| Overrides for fleetbase/core-api's "fleetbase" config
|--------------------------------------------------------------------------
|
| Laravel merges a package's config underneath the application's, so keys set
| here win. Only the default branding is overridden: the logo and icon shown in
| the console and in emails until an administrator uploads their own under
| Admin → Branding. The package default is the upstream Fleetbase artwork.
|
| The files are served by the console (console/public/images). Set
| BRANDING_LOGO_URL / BRANDING_ICON_URL to host them elsewhere.
|
| docker-compose.yml mounts this file into the published API image.
|
*/

$consoleUrl = rtrim((string) env('CONSOLE_HOST', ''), '/');

return [
    'branding' => [
        'logo_url' => env('BRANDING_LOGO_URL', $consoleUrl . '/images/contrust-logo.png'),
        'icon_url' => env('BRANDING_ICON_URL', $consoleUrl . '/images/contrust-icon.png'),
    ],
];
