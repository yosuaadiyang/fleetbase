<?php

namespace App\Http\Controllers;

use App\Support\{ChatSafety, ChatSafetyInput, OrganizationChat, SocketAccessTickets};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

final class ChatSafetyController
{
    public function status(Request $request) { return $this->run($request, 'status'); }
    public function consent(Request $request) { return $this->run($request, 'consent'); }
    public function block(Request $request) { return $this->run($request, 'block'); }
    public function unblock(Request $request) { return $this->run($request, 'unblock'); }
    public function report(Request $request) { return $this->run($request, 'report'); }
    public function reports(Request $request) { return $this->run($request, 'reports'); }
    public function review(Request $request, string $id) { return $this->run($request, 'review', $id); }

    private function run(Request $request, string $action, ?string $id = null)
    {
        $bearer = $request->bearerToken();
        $actor = SocketAccessTickets::actor(is_string($bearer) && str_contains($bearer, '|') ? PersonalAccessToken::findToken($bearer) : null);
        if (!$actor) return ChatSafety::reply(['errors' => ['Authentication required.']], 401);
        if (!OrganizationChat::members($actor['company']->uuid)->where('uuid', $actor['user']->uuid)->exists()) return ChatSafety::denied();
        $read = in_array($action, ['status', 'reports'], true);
        if (!$actor['token']->can($read ? 'chat:read' : 'chat:write')) return ChatSafety::denied();
        if (!ChatSafety::enabled()) return $action === 'status' && !$request->all()
            ? ChatSafety::reply(['enabled' => false]) : ChatSafety::reply(['errors' => ['Chat safety is not enabled.']], 404);
        if ($action === 'status' && !ChatSafetyInput::status($request->all())) return ChatSafety::invalid();
        $bucket = 'contrust:chat-safety:' . ($read ? 'read:' : 'write:') . $actor['user']->uuid;
        if (RateLimiter::tooManyAttempts($bucket, $read ? 120 : 30)) return ChatSafety::reply(['errors' => ['Please try again later.']], 429);
        RateLimiter::hit($bucket, 60);
        try {
            if ($action === 'status') return ChatSafety::reply(ChatSafety::status($actor, $request->input('blocked_after')));
            if ($action === 'reports') return ChatSafety::reports($actor, $request->all());
            return ChatSafety::synchronized($actor['company']->uuid, fn () => match ($action) {
                'consent' => ChatSafety::consent($actor, $request->all()),
                'block', 'unblock' => ChatSafety::block($actor, $request->all(), $action === 'unblock'),
                'report' => ChatSafety::report($actor, $request->all()),
                'review' => ChatSafety::review($actor, $id, $request->all()),
            });
        } catch (\Throwable $exception) {
            // Never log the bearer, report details, content snapshot or DB query.
            return ChatSafety::reply(['errors' => ['Chat safety is temporarily unavailable.']], 503);
        }
    }
}
