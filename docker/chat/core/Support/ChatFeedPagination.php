<?php

namespace Fleetbase\Support;

use Fleetbase\Http\Resources\{ChatAttachment, ChatLog, ChatMessage};
use Fleetbase\Models\ChatChannel;
use InvalidArgumentException;

/** A bounded merge of three independently indexed histories. Callers authorize the room. */
final class ChatFeedPagination
{
    private const TYPES = ['attachment' => ChatAttachment::class, 'log' => ChatLog::class, 'message' => ChatMessage::class];

    public static function page(ChatChannel $room, $limit = 50, $cursor = null, bool $resources = true): array
    {
        if ((!is_int($limit) && !is_string($limit)) || !preg_match('/^[1-9][0-9]{0,2}$/D', (string) $limit) || (int) $limit > 100) {
            throw new InvalidArgumentException('Invalid history limit.');
        }
        $limit = (int) $limit;
        $scope = hash('sha256', $room->company_uuid . ':' . $room->uuid);
        $after = self::decode($scope, $cursor);
        $entries = [];
        foreach (self::TYPES as $type => $resource) {
            $relation = ['message' => 'messages', 'attachment' => 'attachments', 'log' => 'logs'][$type];
            $query = $room->{$relation}();
            if ($room->company_uuid !== null) $query->where('company_uuid', $room->company_uuid);
            if ($after !== null) {
                [$stamp, $boundaryType, $id] = $after;
                $query->where(function ($q) use ($stamp, $type, $boundaryType, $id) {
                    if ($stamp !== null) $q->where('created_at', '<', $stamp)->orWhereNull('created_at');
                    $equal = function ($q) use ($stamp, $type, $boundaryType, $id) {
                        $stamp === null ? $q->whereNull('created_at') : $q->where('created_at', $stamp);
                        if ($type === $boundaryType) $q->where('uuid', '<', $id);
                    };
                    if (strcmp($type, $boundaryType) <= 0) {
                        if ($stamp === null) $q->where($equal);
                        else $q->orWhere($equal);
                    } elseif ($stamp === null) {
                        $q->whereRaw('1 = 0');
                    }
                });
            }
            foreach ($query->reorder()->orderByDesc('created_at')->orderByDesc('uuid')->limit($limit + 1)->get() as $record) {
                $entries[] = ['type' => $type, 'record' => $record, 'stamp' => $record->created_at?->format('Y-m-d H:i:s.u')];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($b['stamp'] ?? '', $a['stamp'] ?? '') ?: strcmp($b['type'], $a['type']) ?: strcmp($b['record']->uuid, $a['record']->uuid));
        $more = count($entries) > $limit;
        $entries = array_slice($entries, 0, $limit);
        $oldest = $entries ? $entries[count($entries) - 1] : null;
        $next = $more && $oldest ? self::encode([$scope, $oldest['stamp'], $oldest['type'], $oldest['record']->uuid]) : null;
        $feed = array_map(function ($entry) use ($resources) {
            $resource = self::TYPES[$entry['type']];
            return ['type' => $entry['type'], 'data' => $resources ? new $resource($entry['record']) : $entry['record'], 'created_at' => $entry['record']->created_at];
        }, array_reverse($entries));
        return ['feed' => collect($feed), 'feed_has_more' => $more, 'feed_next_cursor' => $next];
    }

    private static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function decode(string $scope, $cursor): ?array
    {
        if ($cursor === null) return null;
        if (!is_string($cursor) || strlen($cursor) > 1024 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) throw new InvalidArgumentException('Invalid history cursor.');
        $value = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        if (!is_array($value) || !array_is_list($value) || count($value) !== 4 || $value[0] !== $scope
            || !is_string($value[2]) || !isset(self::TYPES[$value[2]]) || !is_string($value[3]) || !preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $value[3])) throw new InvalidArgumentException('Invalid history cursor.');
        if ($value[1] !== null) {
            if (!is_string($value[1]) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $value[1])) throw new InvalidArgumentException('Invalid history timestamp.');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value[1]);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new InvalidArgumentException('Invalid history timestamp.');
        }
        return [$value[1], $value[2], $value[3]];
    }
}
