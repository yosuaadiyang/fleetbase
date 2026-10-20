<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Bounded keyset pages; tenant/participant constraints remain on the caller's query. */
final class ChatPagination
{
    public static function options(Request $request, string $scope, int $default = 100): array
    {
        $limit = $request->input('limit', $default);
        if ((!is_int($limit) && !is_string($limit))
            || filter_var($limit, FILTER_VALIDATE_INT) === false
            || (int) $limit < 1 || (int) $limit > 500) {
            throw new InvalidArgumentException('Invalid page size.');
        }
        // Directory legacy clients retain their 500-item default. New callers
        // explicitly page at <=100; all queries still have a finite hard cap.
        if ($request->has('limit') && (int) $limit > 100) {
            throw new InvalidArgumentException('Invalid page size.');
        }
        $cursor = $request->input('cursor');
        if ($cursor === null) return ['limit' => (int) $limit, 'after' => null];
        if (!is_string($cursor) || strlen($cursor) > 2048
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $value = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3 || $value[0] !== $scope
            || ($value[1] !== null && (!is_string($value[1]) || strlen($value[1]) > 1024))
            || !is_string($value[2]) || !preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $value[2])) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        if ($value[1] !== null && !str_starts_with($scope, 'members:')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value[1])) {
                throw new InvalidArgumentException('Invalid cursor timestamp.');
            }
            $format = str_contains($value[1], '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
            $date = \DateTimeImmutable::createFromFormat($format, $value[1]);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                throw new InvalidArgumentException('Invalid cursor timestamp.');
            }
        }
        return ['limit' => (int) $limit, 'after' => [$value[1], $value[2]]];
    }

    public static function cursor(string $scope, ?string $value, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$scope, $value, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function apply(Builder $query, array $page, string $column = 'created_at', bool $descending = true): Builder
    {
        // Column names are supplied only by this class's callers, never a request.
        if (!in_array($column, ['created_at', 'name'], true)) throw new InvalidArgumentException('Invalid page column.');
        if ($page['after'] !== null) {
            [$value, $id] = $page['after'];
            $operator = $descending ? '<' : '>';
            if ($value === null) {
                $query->where(function ($q) use ($column, $operator, $id, $descending) {
                    $q->where(fn ($q) => $q->whereNull($column)->where('public_id', $operator, $id));
                    if (!$descending) $q->orWhereNotNull($column);
                });
            } else {
                $query->where(function ($q) use ($column, $operator, $value, $id, $descending) {
                    $q->where($column, $operator, $value)
                        ->orWhere(fn ($q) => $q->where($column, $value)->where('public_id', $operator, $id));
                    if ($descending) $q->orWhereNull($column);
                });
            }
        }
        $direction = $descending ? 'desc' : 'asc';
        return $query->reorder()->orderBy($column, $direction)->orderBy('public_id', $direction);
    }
}
