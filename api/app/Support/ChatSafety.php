<?php

namespace App\Support;

use Fleetbase\Models\{ChatChannel, ChatMessage, User};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

/** Private state: never serialize consent, blocks or reports in a user directory. */
final class ChatSafety
{
    public const VERSION = '2026-10-23.1';
    public const REASONS = ['harassment', 'threat', 'illegal', 'privacy', 'spam', 'other'];
    public const RESOLUTIONS = ['no_violation', 'content_addressed', 'access_addressed', 'support_followup'];

    public static function enabled(): bool { return config('contrust.chat_safety_enabled') === true; }

    /** Block and contribution checks share this lock, including the actual write. */
    public static function synchronized(string $company, \Closure $write)
    {
        return DB::transaction(function () use ($company, $write) {
            if (!DB::table('companies')->where('uuid', $company)->lockForUpdate()->first(['uuid'])) abort(403);
            return $write();
        });
    }

    public static function contribution(User $user, ?string $company = null): ?JsonResponse
    {
        if (!self::enabled()) return null;
        $accepted = DB::table('contrust_chat_consents')->where('company_uuid', $company ?? $user->company_uuid)
            ->where('user_uuid', $user->uuid)->where('policy_version', self::VERSION)->exists();
        return $accepted ? null : self::reply(['code' => 'CHAT_POLICY_REQUIRED', 'policy_version' => self::VERSION,
            'errors' => ['Read and accept the current communication policy before contributing.']], 428);
    }

    /** All pairs are checked so another inviter cannot bypass a recipient's block. */
    public static function invitation(string $company, array $users): ?JsonResponse
    {
        if (!self::enabled()) return null;
        $users = array_values(array_unique($users));
        $blocked = DB::table('contrust_chat_blocks')->where('company_uuid', $company)
            ->whereIn('user_uuid', $users)->whereIn('blocked_user_uuid', $users)->exists();
        return $blocked ? self::blocked() : null;
    }

    public static function message(ChatChannel $channel): ?JsonResponse
    {
        if (!self::enabled()) return null;
        $people = OrganizationChat::members($channel->company_uuid)
            ->whereIn('uuid', $channel->participants()->where('company_uuid', $channel->company_uuid)->select('user_uuid'))
            ->limit(3)->pluck('uuid')->all();
        // Existing shared operational groups remain readable and writable; the
        // block confirmation must explain this boundary. New invitations do not.
        return count($people) <= 2 ? self::invitation($channel->company_uuid, $people) : null;
    }

    public static function status(array $actor, ?string $after = null): array
    {
        $user = $actor['user']; $company = $actor['company']->uuid;
        $consent = DB::table('contrust_chat_consents')->where('company_uuid', $company)->where('user_uuid', $user->uuid)->first();
        $blockedIds = DB::table('contrust_chat_blocks')->where('company_uuid', $company)->where('user_uuid', $user->uuid)->select('blocked_user_uuid');
        $query = User::withoutCache()->whereIn('uuid', $blockedIds)->orderBy('public_id');
        if ($after !== null) $query->where('public_id', '>', $after);
        $blocked = $query->limit(101)->get(['public_id', 'name']); $more = $blocked->count() > 100; $blocked = $blocked->take(100);
        return ['enabled' => true, 'policy_version' => self::VERSION, 'policy_url' => 'https://contrust.website/terms',
            'consented' => $consent && $consent->policy_version === self::VERSION,
            'accepted_at' => $consent?->accepted_at,
            'moderator' => self::moderator($actor),
            'blocked_users' => $blocked->map(fn ($person) => ['id' => $person->public_id, 'name' => $person->name])->all(),
            'blocked_has_more' => $more, 'blocked_next_cursor' => $more ? $blocked->last()->public_id : null,
            'block_scope' => 'direct_messages_and_new_invitations', 'shared_groups_remain_visible' => true];
    }

    public static function consent(array $actor, array $input): JsonResponse
    {
        if (!ChatSafetyInput::consent($input)) return self::invalid();
        $key = ['company_uuid' => $actor['company']->uuid, 'user_uuid' => $actor['user']->uuid];
        $old = DB::table('contrust_chat_consents')->where($key)->first();
        if (!$old || $old->policy_version !== self::VERSION) DB::table('contrust_chat_consents')->updateOrInsert($key,
            ['policy_version' => self::VERSION, 'accepted_at' => now()]);
        return self::reply(['consented' => true, 'policy_version' => self::VERSION]);
    }

    public static function block(array $actor, array $input, bool $remove): JsonResponse
    {
        if (!ChatSafetyInput::block($input)) return self::invalid();
        $target = OrganizationChat::members($actor['company']->uuid)->where('public_id', $input['user'])->first();
        // Unblocking a departed member must remain possible without exposing them
        // in the active directory, but may only remove this actor's stored block.
        if ($remove && !$target) $target = User::withoutCache()->where('public_id', $input['user'])->whereIn('uuid',
            DB::table('contrust_chat_blocks')->where('company_uuid', $actor['company']->uuid)
                ->where('user_uuid', $actor['user']->uuid)->select('blocked_user_uuid'))->first();
        if (!$target || $target->uuid === $actor['user']->uuid) return self::denied();
        $key = ['company_uuid' => $actor['company']->uuid, 'user_uuid' => $actor['user']->uuid, 'blocked_user_uuid' => $target->uuid];
        if ($remove) DB::table('contrust_chat_blocks')->where($key)->delete();
        else DB::table('contrust_chat_blocks')->insertOrIgnore($key + ['created_at' => now()]);
        return self::reply(['user' => $target->public_id, 'blocked' => !$remove]);
    }

    public static function report(array $actor, array $input): JsonResponse
    {
        if (!ChatSafetyInput::report($input)) return self::invalid();
        $company = $actor['company']->uuid; $user = $actor['user'];
        $requestHash = hash('sha256', json_encode([$input['type'], $input['target'], $input['reason'], $input['details'] ?? ''], JSON_THROW_ON_ERROR));
        $old = DB::table('contrust_chat_reports')->where('company_uuid', $company)->where('reporter_uuid', $user->uuid)
            ->where('request_id', $input['request_id'])->first();
        if ($old) return hash_equals($old->request_hash, $requestHash) ? self::reply(['id' => $old->uuid, 'status' => $old->status])
            : self::reply(['code' => 'CHAT_REPORT_CONFLICT', 'errors' => ['Use a new request ID for a different report.']], 409);
        $message = null;
        if ($input['type'] === 'message') {
            $message = ChatMessage::withoutCache()->where('company_uuid', $company)->where('public_id', $input['target'])->first();
            $channel = $message ? OrganizationChat::channel($user, $message->chat_channel_uuid) : null;
            $sender = $channel ? $message->sender : null;
            $target = $sender && $sender->company_uuid === $company ? User::withoutCache()->where('uuid', $sender->user_uuid)->first() : null;
        } else $target = OrganizationChat::members($company)->where('public_id', $input['target'])->first();
        if (!$target || $target->uuid === $user->uuid) return self::denied();
        // Only the selected message and explicit form fields are shared. No
        // surrounding feed, attachments, location, email or phone is included.
        $context = ['type' => $input['type'], 'target' => ['id' => $target->public_id, 'name' => $target->name],
            'message' => $message ? ['id' => $message->public_id, 'content' => $message->content] : null,
            'details' => trim(strip_tags($input['details'] ?? ''))];
        $id = (string) Str::orderedUuid();
        DB::table('contrust_chat_reports')->insert(['uuid' => $id, 'company_uuid' => $company,
            'reporter_uuid' => $user->uuid, 'target_user_uuid' => $target->uuid, 'chat_message_uuid' => $message?->uuid,
            'request_id' => $input['request_id'], 'request_hash' => $requestHash, 'reason' => $input['reason'],
            'encrypted_context' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        return self::reply(['id' => $id, 'status' => 'open'], 201);
    }

    public static function moderator(array $actor): bool
    {
        return !$actor['driver'] && $actor['company']->owner_uuid === $actor['user']->uuid && $actor['token']->can('chat:moderate');
    }

    public static function review(array $actor, string $id, array $input): JsonResponse
    {
        if (!self::moderator($actor)) return self::denied();
        if (!ChatSafetyInput::review($id, $input)) return self::invalid();
        $query = DB::table('contrust_chat_reports')->where('company_uuid', $actor['company']->uuid)->where('uuid', $id);
        if (!$query->exists()) return self::denied();
        $query->update(['status' => $input['status'], 'resolution' => $input['resolution'], 'reviewer_uuid' => $actor['user']->uuid,
            'reviewed_at' => now(), 'updated_at' => now()]);
        return self::reply(['id' => $id, 'status' => $input['status']]);
    }

    public static function reports(array $actor, array $input): JsonResponse
    {
        if (!self::moderator($actor)) return self::denied();
        if (!ChatSafetyInput::reports($input)) return self::invalid();
        $query = DB::table('contrust_chat_reports')->where('company_uuid', $actor['company']->uuid);
        if (isset($input['before'])) $query->where('uuid', '<', $input['before']);
        $rows = $query->orderByDesc('uuid')->limit(26)->get(); $more = $rows->count() > 25; $rows = $rows->take(25);
        $reports = $rows->map(fn ($row) => ['id' => $row->uuid, 'reason' => $row->reason, 'status' => $row->status,
            'resolution' => $row->resolution, 'created_at' => $row->created_at,
            'context' => json_decode(Crypt::decryptString($row->encrypted_context), true, 512, JSON_THROW_ON_ERROR)])->all();
        return self::reply(['reports' => $reports, 'has_more' => $more, 'next_cursor' => $more ? $rows->last()->uuid : null]);
    }

    private static function blocked(): JsonResponse { return self::reply(['code' => 'CHAT_BLOCKED', 'errors' => ['Direct messages or invitations are unavailable for these participants.']], 403); }
    public static function denied(): JsonResponse { return self::reply(['errors' => ['Chat safety action unavailable.']], 403); }
    public static function invalid(): JsonResponse { return self::reply(['errors' => ['Provide valid chat safety details.']], 422); }
    public static function reply(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->header('Cache-Control', 'private, no-store'); }
}
