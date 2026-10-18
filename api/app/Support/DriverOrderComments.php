<?php
namespace App\Support;

use Fleetbase\FleetOps\Models\{Driver, Order};
use Fleetbase\Models\{Comment, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\RateLimiter;

/** Explicit assigned-order scope; generic comments never inherit admin access. */
final class DriverOrderComments
{
    public static function listing(Request $request, Driver $driver, User $user, Order $order): JsonResponse
    {
        $comments = Comment::withoutCache()->where('company_uuid', $driver->company_uuid)
            ->where('subject_uuid', $order->uuid)->where('subject_type', Order::class)
            ->without(['replies'])->with('author')->latest()->limit(500)->get();
        $groups = $comments->groupBy('parent_comment_uuid');
        $render = function ($comment, int $depth = 0) use (&$render, $groups, $user) {
            $result = self::record($comment, $user);
            $result['replies'] = $depth < 5 ? $groups->get($comment->uuid, collect())->map(fn ($child) => $render($child, $depth + 1))->values()->all() : [];
            return $result;
        };
        return self::response($groups->get('', collect())->map(fn ($comment) => $render($comment))->values()->all());
    }

    public static function handle(Request $request, Driver $driver, User $user, string $action, $id): JsonResponse
    {
        if (!in_array($action, ['create', 'find', 'update', 'delete'], true)) return self::denied();
        $fields = $action === 'create' ? ['content', 'subject', 'parent'] : ['content'];
        if (array_diff(array_keys($request->all()), $fields)) return self::denied();
        $comment = null; $parent = null;
        if ($action === 'create') {
            $subject = $request->input('subject'); $parentId = $request->input('parent');
            if ($parentId !== null) {
                if (!is_string($parentId) || $subject !== null) return self::invalid();
                $parent = self::comment($driver, $parentId);
                if (!$parent) return self::denied();
                // Bound nesting so a driver cannot construct an unbounded tree.
                $ancestor = $parent; $depth = 0;
                while ($ancestor->parent_comment_uuid) {
                    if (++$depth >= 5) return self::invalid();
                    $ancestor = self::comment($driver, $ancestor->parent_comment_uuid);
                    if (!$ancestor) return self::denied();
                }
                $order = self::order($driver, $parent->subject_uuid);
            } else {
                if (!is_array($subject) || array_diff(array_keys($subject), ['id', 'type'])
                    || !in_array($subject['type'] ?? null, ['order', Order::class], true) || !is_string($subject['id'] ?? null)) return self::invalid();
                $order = self::order($driver, $subject['id']);
            }
        } else {
            if (!is_string($id)) return self::denied();
            $comment = self::comment($driver, $id);
            if (!$comment) return self::denied();
            $order = self::order($driver, $comment->subject_uuid);
            if (in_array($action, ['update', 'delete'], true) && $comment->author_uuid !== $user->uuid) return self::denied();
        }
        if (!$order) return self::denied();
        if ($action === 'find') return self::response(self::record($comment, $user));
        if ($action === 'delete') {
            if (Comment::where('company_uuid', $driver->company_uuid)->where('parent_comment_uuid', $comment->uuid)->exists()) {
                return self::response(['errors' => ['A comment with replies cannot be deleted.']], 422);
            }
            $comment->delete();
            return self::response(['id' => $comment->public_id, 'deleted' => true]);
        }
        $content = $request->input('content');
        if (!is_string($content)) return self::invalid();
        $content = trim(strip_tags($content));
        if (mb_strlen($content) < 2 || mb_strlen($content) > 4000) return self::invalid();
        $bucket = 'contrust:driver-comment:' . $user->uuid;
        if (RateLimiter::tooManyAttempts($bucket, 30)) return self::response(['errors' => ['Please wait before posting another comment.']], 429);
        RateLimiter::hit($bucket, 60);
        if ($action === 'create') {
            $comment = new Comment();
            $comment->forceFill(['company_uuid' => $driver->company_uuid, 'author_uuid' => $user->uuid,
                'subject_uuid' => $order->uuid, 'subject_type' => Order::class,
                'parent_comment_uuid' => $parent?->uuid, 'content' => $content]);
        } else $comment->content = $content;
        $comment->save();
        return self::response(self::record($comment->fresh(['author']), $user), $action === 'create' ? 201 : 200);
    }

    private static function comment(Driver $driver, string $id): ?Comment
    {
        return Comment::withoutCache()->where('company_uuid', $driver->company_uuid)->where('subject_type', Order::class)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->first();
    }
    private static function order(Driver $driver, string $id): ?Order
    {
        return Order::withoutCache()->where('company_uuid', $driver->company_uuid)->where('driver_assigned_uuid', $driver->uuid)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->first();
    }
    private static function record(Comment $comment, User $user): array
    {
        return ['id' => $comment->public_id, 'content' => $comment->content,
            'author' => ['id' => $comment->author?->public_id, 'name' => $comment->author?->name ?? 'Former member', 'avatar_url' => $comment->author?->avatar_url],
            'created_at' => $comment->created_at?->toISOString(), 'updated_at' => $comment->updated_at?->toISOString(),
            'editable' => $comment->author_uuid === $user->uuid, 'replies' => []];
    }
    private static function response(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->header('Cache-Control', 'no-store, private'); }
    private static function denied(): JsonResponse { return self::response(['errors' => ['You do not have permission to perform this action.']], 403); }
    private static function invalid(): JsonResponse { return self::response(['errors' => ['Provide a valid assigned-order comment of 2 to 4000 characters.']], 422); }
}
