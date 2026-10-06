{{-- Overrides fleetbase/core-api's views/mail/test.blade.php (Laravel checks
     resources/views/vendor/<namespace> before a package's own views), so the test email
     names this installation (APP_NAME) instead of the upstream brand. Otherwise the same. --}}
<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
@if($currentHour < 12)
    Good Morning, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!
@elseif($currentHour < 18)
    Good Afternoon, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!
@else
    Good Evening, {{ \Fleetbase\Support\Utils::delinkify($user->name) }}!
@endif
</h2>

<p>🎉 This is a test email from {{ config('app.name') }} to confirm that your mail configuration works.</p>
<table>
    <tbody>
        <tr>
            <td><strong>MAILER:</strong></td>
            <td>{{ strtoupper($mailer) }}</td>
        </tr>
        <tr>
            <td><strong>ENVIRONMENT:</strong></td>
            <td>{{ strtoupper(app()->environment()) }}</td>
        </tr>
    </tbody>
</table>
</x-mail-layout>
