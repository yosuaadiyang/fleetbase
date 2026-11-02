<?php

namespace App\Http\Controllers;

use App\Support\{ChatSafety, OperationalReports, OrganizationChat, SocketAccessTickets};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, RateLimiter};
use Laravel\Sanctum\PersonalAccessToken;

/** Core API middleware binds the real actor/session for original IAM rules. */
final class OperationalSafetyController extends \Illuminate\Routing\Controller
{
    public function status(Request $request) { return $this->run($request, 'status'); }
    public function preview(Request $request) { return $this->run($request, 'preview'); }
    public function report(Request $request) { return $this->run($request, 'report'); }

    private function run(Request $request, string $action)
    {
        $bearer = $request->bearerToken();
        $actor = SocketAccessTickets::actor(is_string($bearer) && str_contains($bearer, '|') ? PersonalAccessToken::findToken($bearer) : null);
        if (!$actor || $request->user()?->uuid !== $actor['user']->uuid) return ChatSafety::reply(['errors' => ['Authentication required.']], 401);
        if (!$actor['token']->can('*') || !OrganizationChat::members($actor['company']->uuid)->where('uuid', $actor['user']->uuid)->exists()) return ChatSafety::denied();
        if (!OperationalReports::enabled()) return $action === 'status' && !$request->all()
            ? ChatSafety::reply(['enabled' => false]) : ChatSafety::reply(['errors' => ['Operational reporting is not enabled.']], 404);
        if ($action === 'status' && $request->all()) return ChatSafety::invalid();
        $read = $action !== 'report';
        $bucket = 'contrust:chat-safety:' . ($read ? 'read:' : 'write:') . $actor['user']->uuid;
        if (RateLimiter::tooManyAttempts($bucket, $read ? 120 : 30)) return ChatSafety::reply(['errors' => ['Please try again later.']], 429);
        RateLimiter::hit($bucket, 60);
        $guard = Auth::guard();
        $previous = $guard->user();
        if ($previous && $previous->uuid !== $actor['user']->uuid) return ChatSafety::denied();
        // API basic auth binds the request/session but does not log in a guard.
        // Original `self.*` IAM directives need the identical actor on it too.
        // Restore the guard in finally; never persist a login/session identity.
        $guard->setUser($actor['user']);
        try {
            if ($action === 'status') return ChatSafety::reply(['enabled' => true, 'policy_version' => OperationalReports::POLICY_VERSION,
                'policy_url' => 'https://contrust.website/terms', 'report_types' => \App\Support\OperationalReportContext::TYPES]);
            if ($action === 'preview') return OperationalReports::preview($actor, $request->all());
            return ChatSafety::synchronized($actor['company']->uuid, fn () => OperationalReports::report($actor, $request->all()));
        } catch (\Throwable $failure) {
            return ChatSafety::reply(['errors' => ['Operational reporting is temporarily unavailable.']], 503);
        } finally {
            if ($previous) $guard->setUser($previous);
            else $guard->forgetUser();
        }
    }
}
