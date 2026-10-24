<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\{ChatChannel, ChatMessage, ChatParticipant, ChatReceipt, Company, CompanyUser, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, RateLimiter};

/** Organization membership and room participation are separate, mandatory scopes. */
final class OrganizationChat
{
    public static function members(string $company)
    {
        $members = CompanyUser::where('company_uuid', $company)->where('status', 'active')->select('user_uuid');
        $revoked = CompanyUser::withTrashed()->where('company_uuid', $company)
            ->where(fn ($q) => $q->where('status', '!=', 'active')->orWhereNull('status')->orWhereNotNull('deleted_at'))->select('user_uuid');
        $drivers = Driver::withoutCache()->where('company_uuid', $company)->select('user_uuid');
        $owner = Company::withoutCache()->where('uuid', $company)->value('owner_uuid');
        return User::withoutCache()->where('status', 'active')->whereNotIn('uuid', $revoked)
            ->where(fn ($q) => $q->whereIn('uuid', $members)->orWhereIn('uuid', $drivers)->orWhere('uuid', $owner));
    }

    public static function channel(User $user, string $id): ?ChatChannel
    {
        if (!self::members($user->company_uuid)->where('uuid', $user->uuid)->exists()) return null;
        return self::resource(ChatChannel::class, $user->company_uuid, $id)
            ->whereHas('participants', fn ($q) => $q->where('company_uuid', $user->company_uuid)->where('user_uuid', $user->uuid))->first();
    }

    public static function handle(Request $request, User $user, string $action): JsonResponse
    {
        $write = !in_array($action, ['getAvailablePartificants', 'query', 'find'], true);
        return ChatSafety::enabled() && $write && is_string($user->company_uuid)
            ? ChatSafety::synchronized($user->company_uuid, fn () => self::dispatch($request, $user, $action))
            : self::dispatch($request, $user, $action);
    }

    private static function dispatch(Request $request, User $user, string $action): JsonResponse
    {
        if (!is_string($user->company_uuid) || !self::members($user->company_uuid)->where('uuid', $user->uuid)->exists()) return self::denied();
        $company = $user->company_uuid;
        // Ember posts a named resource; Navigator posts flat JSON. Normalize
        // only a sole object wrapper, then apply the same ownership allowlist.
        foreach (['chatChannel', 'chat_channel'] as $wrapper) {
            if (!$request->has($wrapper)) continue;
            $payload = $request->input($wrapper);
            if (count($request->all()) !== 1 || !is_array($payload) || array_is_list($payload)) return self::invalid();
            $request->replace($payload);
            break;
        }
        $fields = [
            'getAvailablePartificants' => ['channel', 'limit', 'cursor'], 'query' => ['sort', 'limit', 'cursor'], 'find' => ['limit', 'cursor'],
            'create' => ['name', 'participants'], 'update' => ['name'], 'delete' => [],
            'sendMessage' => ['sender', 'content', 'files'], 'deleteMessage' => [],
            'createReadReceipt' => ['participant'], 'addParticipant' => ['user'], 'removeParticipant' => [],
        ];
        if (!isset($fields[$action]) || array_diff(array_keys($request->all()), $fields[$action])) return self::denied();
        if (in_array($action, ['create', 'update', 'sendMessage', 'addParticipant'], true)
            && ($response = ChatSafety::contribution($user))) return $response;
        $id = $request->route()->parameter('id');
        if ($action === 'getAvailablePartificants') {
            $query = self::members($company)->where('uuid', '!=', $user->uuid);
            $scope = 'members:all';
            if ($request->has('channel')) {
                $channelId = $request->input('channel');
                $channel = is_string($channelId) ? self::channel($user, $channelId) : null;
                if (!$channel) return self::denied();
                $query->whereNotIn('uuid', $channel->participants()->select('user_uuid'));
                $scope = 'members:' . $channel->public_id;
            }
            try { $page = ChatPagination::options($request, $scope, 500); }
            catch (\InvalidArgumentException) { return self::invalid(); }
            return self::response(ChatPagination::apply($query, $page, 'name', false)->limit($page['limit'])->get()
                ->map(fn ($u) => self::userRecord($u) + ['cursor' => ChatPagination::cursor($scope, $u->name, $u->public_id)])->all());
        }
        if ($action === 'query') {
            try { $page = ChatPagination::options($request, 'channels'); }
            catch (\InvalidArgumentException) { return self::invalid(); }
            $channels = ChatChannel::withoutCache()->where('company_uuid', $company)
                ->whereHas('participants', fn ($q) => $q->where('company_uuid', $company)->where('user_uuid', $user->uuid));
            $channels = ChatPagination::apply($channels, $page)->limit($page['limit'])->get();
            return self::response($channels->map(fn ($c) => self::channelRecord($c, $user, false))->all());
        }
        if ($action === 'create') {
            $name = self::text($request->input('name'), 2, 120);
            $ids = $request->input('participants');
            if ($name === null || !is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 20
                || count(array_filter($ids, 'is_string')) !== count($ids)) return self::invalid();
            $ids = array_values(array_unique($ids));
            $people = self::members($company)->whereIn('public_id', $ids)->get();
            if ($people->count() !== count($ids)) return self::denied();
            $people = $people->reject(fn ($p) => $p->uuid === $user->uuid);
            if ($people->isEmpty()) return self::invalid();
            if ($response = ChatSafety::invitation($company, array_merge([$user->uuid], $people->pluck('uuid')->all()))) return $response;
            if ($limited = self::limit($user, 'create', 10)) return $limited;
            $channel = DB::transaction(function () use ($company, $user, $name, $people) {
                $channel = new ChatChannel();
                $channel->forceFill(['company_uuid' => $company, 'created_by_uuid' => $user->uuid, 'name' => $name])->save();
                // ChatChannel's created hook adds its creator automatically.
                foreach ($people as $person) self::add($channel, $person);
                return $channel;
            });
            return self::response(self::channelRecord($channel, $user), 201);
        }

        $message = null; $targetParticipant = null;
        if (in_array($action, ['deleteMessage', 'createReadReceipt'], true)) {
            $messageId = $request->route()->parameter('chatMessageId');
            $message = is_string($messageId) ? self::resource(ChatMessage::class, $company, $messageId)->first() : null;
            $channel = $message ? self::channel($user, $message->chat_channel_uuid) : null;
        } elseif ($action === 'removeParticipant') {
            $participantId = $request->route()->parameter('participantId');
            $targetParticipant = is_string($participantId) ? self::resource(ChatParticipant::class, $company, $participantId)->first() : null;
            $channel = $targetParticipant ? self::channel($user, $targetParticipant->chat_channel_uuid) : null;
        } else $channel = is_string($id) ? self::channel($user, $id) : null;
        if (!$channel) return self::denied();
        $actor = $channel->participants()->where('company_uuid', $company)->where('user_uuid', $user->uuid)->first();
        if (!$actor) return self::denied();
        if ($action === 'find') {
            try { $page = ChatPagination::options($request, 'messages:' . $channel->public_id); }
            catch (\InvalidArgumentException) { return self::invalid(); }
            return self::response(self::channelRecord($channel, $user, true, $page));
        }
        if (in_array($action, ['update', 'delete', 'addParticipant'], true) && $channel->created_by_uuid !== $user->uuid) return self::denied();
        if ($action === 'update') {
            $name = self::text($request->input('name'), 2, 120);
            if ($name === null) return self::invalid();
            $channel->name = $name; $channel->save();
            return self::response(self::channelRecord($channel, $user));
        }
        if ($action === 'delete') {
            $channel->delete();
            return self::response(['id' => $channel->public_id, 'deleted' => true]);
        }
        if ($action === 'addParticipant') {
            $userId = $request->input('user');
            $person = is_string($userId) ? self::members($company)->where('public_id', $userId)->first() : null;
            if (!$person) return self::denied();
            if ($response = ChatSafety::invitation($company, array_merge($channel->participants()->pluck('user_uuid')->all(), [$person->uuid]))) return $response;
            $participant = self::add($channel, $person);
            return $participant ? self::response(self::participantRecord($participant), 201) : self::invalid();
        }
        if ($action === 'removeParticipant') {
            if ($targetParticipant->user_uuid !== $user->uuid && $channel->created_by_uuid !== $user->uuid) return self::denied();
            $targetParticipant->delete();
            return self::response(['id' => $targetParticipant->public_id, 'deleted' => true]);
        }
        if ($action === 'sendMessage') {
            if ($response = ChatSafety::message($channel)) return $response;
            $sender = $request->input('sender');
            if ($sender !== null && !in_array($sender, [$actor->uuid, $actor->public_id], true)) return self::denied();
            // Attachments need their own file ownership contract before enabling.
            if ($request->input('files', []) !== []) return self::invalid();
            $content = self::text($request->input('content'), 1, 4000);
            if ($content === null) return self::invalid();
            if ($limited = self::limit($user, 'send', 60)) return $limited;
            $message = new ChatMessage();
            $message->forceFill(['company_uuid' => $company, 'chat_channel_uuid' => $channel->uuid, 'sender_uuid' => $actor->uuid, 'content' => $content])->save();
            // Model lifecycle broadcasts provide in-app delivery. Do not queue
            // upstream push notifications with unchecked/stale recipient snapshots.
            return self::response(self::messageRecord($message->fresh()), 201);
        }
        if ($action === 'deleteMessage') {
            if ($message->sender_uuid !== $actor->uuid) return self::denied();
            $message->delete();
            return self::response(['id' => $message->public_id, 'deleted' => true]);
        }
        if ($action === 'createReadReceipt') {
            $participant = $request->input('participant');
            if ($participant !== null && !in_array($participant, [$actor->uuid, $actor->public_id], true)) return self::denied();
            $receipt = DB::transaction(function () use ($channel, $message, $actor, $company) {
                // Serialize receipt creation per room without relying on a missing unique index.
                ChatChannel::where('uuid', $channel->uuid)->lockForUpdate()->first();
                $receipt = ChatReceipt::withoutCache()->where('company_uuid', $company)->where('chat_message_uuid', $message->uuid)->where('participant_uuid', $actor->uuid)->first();
                if (!$receipt) {
                    $receipt = new ChatReceipt();
                    $receipt->forceFill(['company_uuid' => $company, 'chat_message_uuid' => $message->uuid, 'participant_uuid' => $actor->uuid])->save();
                }
                return $receipt;
            });
            return self::response(self::receiptRecord($receipt));
        }
        return self::denied();
    }

    private static function add(ChatChannel $channel, User $user): ?ChatParticipant
    {
        return DB::transaction(function () use ($channel, $user) {
            // Parallel invitations must not create duplicates or exceed the cap.
            ChatChannel::where('uuid', $channel->uuid)->lockForUpdate()->first();
            $participant = $channel->participants()->where('company_uuid', $channel->company_uuid)->where('user_uuid', $user->uuid)->first();
            if ($participant) return $participant;
            if ($channel->participants()->count() >= 21) return null;
            $participant = new ChatParticipant();
            $participant->forceFill(['company_uuid' => $channel->company_uuid, 'chat_channel_uuid' => $channel->uuid, 'user_uuid' => $user->uuid])->save();
            return $participant;
        });
    }

    private static function resource(string $model, string $company, string $id)
    {
        return $model::withoutCache()->where('company_uuid', $company)->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id));
    }
    private static function userRecord(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name, 'avatar_url' => $user->avatar_url];
    }
    private static function participantRecord(ChatParticipant $participant, ?array $members = null): array
    {
        $user = $members === null ? self::members($participant->company_uuid)->where('uuid', $participant->user_uuid)->first()
            : ($members[$participant->user_uuid] ?? null);
        return ['id' => $participant->public_id, 'user' => $user?->public_id, 'name' => $user?->name ?? 'Former member',
            'avatar_url' => $user?->avatar_url, 'is_online' => $user ? $user->isOnline() : false,
            'last_seen_at' => $user?->lastSeenAt()?->toISOString(), 'created_at' => $participant->created_at?->toISOString()];
    }
    private static function receiptRecord(ChatReceipt $receipt, ?array $participants = null): array
    {
        $participant = $participants === null ? null : ($participants[$receipt->participant_uuid] ?? null);
        return ['id' => $receipt->uuid, 'participant' => $participants === null ? $receipt->participant?->public_id : ($participant['id'] ?? null),
            'participant_name' => $participants === null ? ($receipt->participant?->name ?? 'Former member') : ($participant['name'] ?? 'Former member'),
            'read_at' => $receipt->read_at?->toISOString()];
    }
    private static function messageRecord(ChatMessage $message, ?array $participants = null): array
    {
        return ['id' => $message->public_id, 'content' => $message->content,
            'sender' => $participants === null ? ($message->sender ? self::participantRecord($message->sender) : null) : ($participants[$message->sender_uuid] ?? null),
            'attachments' => [], 'receipts' => $message->receipts->map(fn ($r) => self::receiptRecord($r, $participants))->all(),
            'created_at' => $message->created_at?->toISOString(), 'updated_at' => $message->updated_at?->toISOString()];
    }
    private static function channelRecord(ChatChannel $channel, User $user, bool $feed = true, ?array $page = null): array
    {
        $page ??= ['limit' => 100, 'after' => null];
        $participants = $channel->participants()->where('company_uuid', $channel->company_uuid)->get();
        $members = self::members($channel->company_uuid)->whereIn('uuid', $participants->pluck('user_uuid'))->get()->keyBy('uuid')->all();
        $participantRecords = $participants->mapWithKeys(fn ($p) => [$p->uuid => self::participantRecord($p, $members)])->all();
        $query = ChatMessage::withoutCache()->with('receipts')->where('chat_channel_uuid', $channel->uuid)->where('company_uuid', $channel->company_uuid);
        $baseQuery = clone $query;
        $messages = ChatPagination::apply($query, $page)->limit($feed ? $page['limit'] + 1 : 1)->get();
        $hasMore = $feed && $messages->count() > $page['limit'];
        if ($feed) $messages = $messages->take($page['limit']);
        $last = $page['after'] === null ? $messages->first()
            : ChatPagination::apply($baseQuery, ['after' => null])->first();
        $oldest = $messages->last();
        return ['id' => $channel->public_id, 'name' => $channel->name, 'title' => $channel->name ?: 'Organization chat',
            'created_by' => $channel->createdBy?->public_id, 'participants' => array_values($participantRecords),
            'last_message' => $last ? self::messageRecord($last, $participantRecords) : null, 'unread_count' => $channel->getUnreadMessageCountForUser($user),
            'feed' => $feed ? $messages->reverse()->values()->map(fn ($m) => ['type' => 'message', 'data' => self::messageRecord($m, $participantRecords), 'created_at' => $m->created_at?->toISOString()])->all() : [],
            'history_limit' => $page['limit'], 'history_has_more' => $hasMore,
            'history_next_cursor' => $hasMore && $oldest ? ChatPagination::cursor('messages:' . $channel->public_id, $oldest->getRawOriginal('created_at'), $oldest->public_id) : null,
            'cursor' => ChatPagination::cursor('channels', $channel->getRawOriginal('created_at'), $channel->public_id),
            'created_at' => $channel->created_at?->toISOString(), 'updated_at' => $channel->updated_at?->toISOString()];
    }
    private static function text($value, int $min, int $max): ?string
    {
        if (!is_string($value)) return null;
        $value = trim(strip_tags($value));
        return mb_strlen($value) >= $min && mb_strlen($value) <= $max ? $value : null;
    }
    private static function limit(User $user, string $action, int $max): ?JsonResponse
    {
        $key = 'contrust:chat:' . $action . ':' . $user->uuid;
        if (RateLimiter::tooManyAttempts($key, $max)) return self::response(['errors' => ['Please wait before trying again.']], 429);
        RateLimiter::hit($key, 60);
        return null;
    }
    private static function response(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->header('Cache-Control', 'no-store, private'); }
    private static function denied(): JsonResponse { return self::response(['errors' => ['You do not have permission to perform this action.']], 403); }
    private static function invalid(): JsonResponse { return self::response(['errors' => ['Provide valid organization chat details.']], 422); }
}
