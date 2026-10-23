<?php

namespace App\Support;

use Fleetbase\Models\{ChatChannel, ChatMessage, ChatParticipant, ChatReceipt, Company, CompanyUser, User};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Web chat uses separate controllers from Navigator; enforce the same room boundary. */
final class InternalChatAccess
{
    private const RESOURCES = ['ChatChannel' => 'chatChannel', 'ChatMessage' => 'chatMessage',
        'ChatParticipant' => 'chatParticipant', 'ChatReceipt' => 'chatReceipt', 'ChatAttachment' => 'chatAttachment', 'ChatLog' => 'chatLog'];

    public static function kind(Request $request): ?string
    {
        $class = $request->route()?->getActionName() ?? '';
        foreach (self::RESOURCES as $kind => $wrapper) {
            if (str_starts_with($class, 'Fleetbase\\Http\\Controllers\\Internal\\v1\\' . $kind . 'Controller@')) return $kind;
        }
        return null;
    }

    public static function callback(Request $request, ?\Closure $original): ?\Closure
    {
        if (!self::kind($request)) return $original;
        return function ($query, $request) use ($original) {
            if ($original) $original($query, $request);
            $company = session('company'); $user = session('user'); $kind = self::kind($request);
            $rooms = ChatParticipant::withoutCache()->select('chat_channel_uuid')->where('company_uuid', $company)->where('user_uuid', $user);
            $query->where($query->getModel()->qualifyColumn('company_uuid'), $company);
            if ($kind === 'ChatChannel') $query->whereIn($query->getModel()->qualifyColumn('uuid'), $rooms);
            elseif ($kind === 'ChatReceipt') $query->whereIn($query->getModel()->qualifyColumn('chat_message_uuid'), ChatMessage::withoutCache()->select('uuid')->where('company_uuid', $company)->whereIn('chat_channel_uuid', $rooms));
            else $query->whereIn($query->getModel()->qualifyColumn('chat_channel_uuid'), $rooms);
        };
    }

    public static function check(Request $request, User $user)
    {
        $kind = self::kind($request);
        if (!$kind) return null;
        $company = session('company');
        if ($user->status !== 'active' || !is_string($company) || !Str::isUuid($company)
            || !Company::where('uuid', $company)->exists()
            || (!CompanyUser::where('company_uuid', $company)->where('user_uuid', $user->uuid)->where('status', 'active')->exists()
                && !Company::where('uuid', $company)->where('owner_uuid', $user->uuid)->exists())) return self::denied();
        // Never accept client query directives which expand unrelated private models.
        if ($request->hasAny(['contain', 'with', 'expand', 'include', 'columns', 'fields', 'raw', 'with_count', 'count'])) return self::invalid();
        $action = $request->route()->getActionMethod();
        if ($action === 'queryRecord') {
            if ($request->has('limit') && (!is_scalar($request->input('limit')) || !preg_match('/^[1-9][0-9]{0,2}$/D', (string) $request->input('limit')) || (int) $request->input('limit') > 100)) return self::invalid();
            return null; // Mandatory room SQL scope is added by the controller trait.
        }
        if ($action === 'getFeed') return self::room($request->route()->parameter('channelId'), $company, $user) ? null : self::denied();
        if ($action === 'getAvailableParticipants') {
            $channel = $request->input('channel');
            if ($channel !== null && !self::room($channel, $company, $user)) return self::denied();
            $search = $request->input('query');
            if ($search !== null && (!is_string($search) || mb_strlen($search) > 120)) return self::invalid();
            $people = User::where('status', 'active')->where('uuid', '!=', $user->uuid)->whereHas('companyUsers', fn ($q) => $q->where('company_uuid', $company)->where('status', 'active'));
            if ($search) $people->where('name', 'like', '%' . $search . '%');
            if ($channel !== null) $people->whereNotIn('uuid', self::room($channel, $company, $user)->participants()->pluck('user_uuid'));
            $scope = 'members:web:' . hash('sha256', $company . ':' . ($channel ?? '') . ':' . ($search ?? ''));
            try { $page = ChatPagination::options($request, $scope, 25); }
            catch (\InvalidArgumentException $error) { return self::invalid(); }
            $rows = ChatPagination::apply($people, $page, 'name', false)->limit($page['limit'] + 1)->get();
            $more = $rows->count() > $page['limit']; $rows = $rows->take($page['limit']); $last = $rows->last();
            return self::reply(['users' => \Fleetbase\Http\Resources\User::collection($rows), 'meta' => [
                'has_more' => $more, 'next_cursor' => $more && $last ? ChatPagination::cursor($scope, $last->name, $last->public_id) : null,
            ]]);
        }
        if ($action === 'getUnreadCount') {
            $rooms = ChatChannel::withoutCache()->where('company_uuid', $company)->whereHas('participants', fn ($q) => $q->where('company_uuid', $company)->where('user_uuid', $user->uuid))->get();
            return self::reply(['unreadCount' => $rooms->sum(fn ($room) => $room->getUnreadMessageCountForUser($user))]);
        }
        if ($action === 'getUnreadCountForChannel') return self::room($request->route()->parameter('channelId'), $company, $user) ? null : self::denied();
        if (!in_array($action, ['findRecord', 'createRecord', 'updateRecord', 'deleteRecord'], true)) return self::denied();
        $wrapper = self::RESOURCES[$kind];
        $body = $request->input($wrapper, []);
        if (!is_array($body)) return self::invalid();
        if (isset($body['company_uuid']) && $body['company_uuid'] !== $company) return self::denied();
        $record = null; $room = null;
        if ($action !== 'createRecord') {
            $record = self::record('Fleetbase\\Models\\' . $kind, $request->route()->parameter(Str::snake($kind)), $company);
            if (!$record) return self::denied();
            $roomId = $kind === 'ChatChannel' ? $record->uuid : ($kind === 'ChatReceipt' ? $record->chatMessage?->chat_channel_uuid : $record->chat_channel_uuid);
            $room = self::room($roomId, $company, $user);
            if (!$room) return self::denied();
            if ($action === 'findRecord') return null;
        }
        if (in_array($kind, ['ChatAttachment', 'ChatLog'], true)) return self::denied();
        if ($kind === 'ChatChannel') {
            if ($action !== 'createRecord' && $room->created_by_uuid !== $user->uuid) return self::denied();
            if ($action === 'deleteRecord') return self::remove($record);
            if (isset($body['created_by_uuid']) && $body['created_by_uuid'] !== $user->uuid) return self::denied();
            $name = self::text($body['name'] ?? null, 2, 120);
            $meta = $body['meta'] ?? [];
            if ($name === null || (!is_array($meta) && !is_object($meta)) || strlen(json_encode($meta)) > 4096) return self::invalid();
            $safe = ['name' => $name, 'meta' => $meta];
            if ($action === 'createRecord') {
                $people = $body['participants'] ?? [];
                if (!is_array($people) || !array_is_list($people) || count($people) > 50) return self::invalid();
                foreach ($people as $id) if (!self::member($id, $company)) return self::denied();
                $safe['participants'] = array_values(array_unique($people));
            }
            $request->replace([$wrapper => $safe]);
            return null;
        }
        if ($kind === 'ChatMessage') {
            if ($action === 'createRecord') $room = self::room($body['chat_channel_uuid'] ?? null, $company, $user);
            if (!$room) return self::denied();
            $participant = $room->participants()->where('company_uuid', $company)->where('user_uuid', $user->uuid)->first();
            if (!$participant || ($record && $record->sender_uuid !== $participant->uuid)) return self::denied();
            if ($action === 'deleteRecord') return self::remove($record);
            if (isset($body['sender_uuid']) && !in_array($body['sender_uuid'], [$participant->uuid, $participant->public_id], true)) return self::denied();
            if (isset($body['chat_channel_uuid']) && !in_array($body['chat_channel_uuid'], [$room->uuid, $room->public_id], true)) return self::denied();
            if (($body['attachment_files'] ?? []) !== []) return self::invalid();
            $content = self::text($body['content'] ?? null, 1, 4000);
            if ($content === null) return self::invalid();
            $request->replace([$wrapper => ['chat_channel_uuid' => $room->uuid, 'sender_uuid' => $participant->uuid, 'content' => $content, 'attachment_files' => []]]);
            return null;
        }
        if ($kind === 'ChatParticipant') {
            if ($action === 'updateRecord') return self::denied();
            if ($action === 'deleteRecord') return $record->user_uuid === $user->uuid || $room->created_by_uuid === $user->uuid ? null : self::denied();
            $room = self::room($body['chat_channel_uuid'] ?? null, $company, $user);
            $person = self::member($body['user_uuid'] ?? null, $company);
            if (!$room || !$person || $room->created_by_uuid !== $user->uuid) return self::denied();
            $existing = $room->participants()->where('company_uuid', $company)->where('user_uuid', $person->uuid)->first();
            if ($existing) return self::reply(['chatParticipant' => new \Fleetbase\Http\Resources\ChatParticipant($existing)]);
            $request->replace([$wrapper => ['chat_channel_uuid' => $room->uuid, 'user_uuid' => $person->uuid]]);
            return null;
        }
        if ($kind === 'ChatReceipt') {
            if ($action !== 'createRecord') return self::denied();
            $message = self::record(ChatMessage::class, $body['chat_message_uuid'] ?? null, $company);
            $room = $message ? self::room($message->chat_channel_uuid, $company, $user) : null;
            $participant = $room?->participants()->where('company_uuid', $company)->where('user_uuid', $user->uuid)->first();
            if (!$participant || (isset($body['participant_uuid']) && !in_array($body['participant_uuid'], [$participant->uuid, $participant->public_id], true))) return self::denied();
            $request->replace([$wrapper => ['chat_message_uuid' => $message->uuid, 'participant_uuid' => $participant->uuid, 'read_at' => now()->toISOString()]]);
            return null;
        }
        return self::denied();
    }

    private static function record(string $model, $id, string $company)
    {
        if (!is_string($id) || strlen($id) > 150) return null;
        $publicId = (new $model())->isColumn('public_id');
        return $model::withoutCache()->where('company_uuid', $company)->where(function ($q) use ($id, $publicId) {
            $q->where('uuid', $id);
            if ($publicId) $q->orWhere('public_id', $id);
        })->first();
    }
    private static function room($id, string $company, User $user): ?ChatChannel
    {
        $room = self::record(ChatChannel::class, $id, $company);
        return $room && $room->participants()->where('company_uuid', $company)->where('user_uuid', $user->uuid)->exists() ? $room : null;
    }
    private static function member($id, string $company): ?User
    {
        if (!is_string($id) || strlen($id) > 150) return null;
        return User::where('status', 'active')->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->whereHas('companyUsers', fn ($q) => $q->where('company_uuid', $company)->where('status', 'active'))->first();
    }
    private static function text($value, int $min, int $max): ?string
    {
        if (!is_string($value)) return null;
        $value = trim(strip_tags($value));
        return mb_strlen($value) >= $min && mb_strlen($value) <= $max ? $value : null;
    }
    private static function remove($record)
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => $record->delete());
            // Deleted chat relationships must not be serialized after mutation.
            return response()->noContent()->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Contrust chat deletion failed', [
                'exception_class' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine(),
            ]);
            return self::reply(['errors' => ['Unable to delete chat. Please try again.']], 500);
        }
    }
    private static function reply(array $data, int $status = 200) { return response()->json($data, $status)->header('Cache-Control', 'no-store, private'); }
    private static function denied() { return self::reply(['errors' => ['You do not have permission to access this chat.']], 403); }
    private static function invalid() { return self::reply(['errors' => ['Provide valid organization chat details.']], 422); }
}
