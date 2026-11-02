<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\{FuelReport, Issue, Order, Proof};
use Fleetbase\Models\{Comment, Permission};
use Fleetbase\Support\Auth;
use Illuminate\Support\Facades\DB;

/** Reuse original resource permissions AND per-record directives for staff. */
final class OperationalContentAccess
{
    private const MODELS = ['order_comment' => Comment::class, 'issue' => Issue::class,
        'fuel_report' => FuelReport::class, 'proof' => Proof::class];

    public static function context(array $actor, string $type, string $id): ?array
    {
        // The pilot's regular user sessions have full PAT abilities. A narrow
        // chat token must never gain operational-record reads through reports.
        if (!$actor['token']->can('*') || !isset(self::MODELS[$type]) || !ChatSafetyInput::publicId($id)) return null;
        $model = self::MODELS[$type];
        $columns = ['uuid', 'public_id'];
        if ($type === 'order_comment') $columns = array_merge($columns, ['subject_uuid', 'subject_type']);
        if ($type === 'proof') $columns[] = 'order_uuid';
        $query = $model::withoutCache()->setEagerLoads([])->where('company_uuid', $actor['company']->uuid)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id));
        $record = $query->first($columns);
        if (!$record) return null;
        if (!$actor['driver']) {
            if ($type === 'order_comment') {
                if ($record->subject_type !== Order::class
                    || !self::staffRecord($actor, Comment::class, 'Fleetbase\\Http\\Controllers\\Internal\\v1\\CommentController', $record->uuid)
                    || !self::staffRecord($actor, Order::class, 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', $record->subject_uuid)) return null;
            } elseif ($type === 'proof') {
                if (!self::staffRecord($actor, Order::class, 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController', $record->order_uuid)) return null;
            } else {
                $controller = 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\' . ($type === 'issue' ? 'IssueController' : 'FuelReportController');
                if (!self::staffRecord($actor, $model, $controller, $record->uuid)) return null;
            }
        }
        $scope = ['company_uuid' => $actor['company']->uuid, 'user_uuid' => $actor['user']->uuid,
            'driver_uuid' => $actor['driver']?->uuid,
            'staff_targets' => $actor['driver'] ? [] : [$type => [$record->public_id]]];
        return (new OperationalReportContext(DB::connection()->getPdo()))->resolve($scope, $type, $record->public_id);
    }

    private static function staffRecord(array $actor, string $model, string $controller, ?string $uuid): bool
    {
        if (!$uuid || Auth::getUserFromSession()?->uuid !== $actor['user']->uuid || session('company') !== $actor['company']->uuid) return false;
        $original = app($controller);
        if (!is_callable([$original, 'getService']) || !is_callable([$original, 'getResourceSingularName'])) return false;
        $service = $original->getService();
        $resource = Auth::getPermissionResourceFromController($original);
        $names = [$service . ' view ' . $resource, $service . ' * ' . $resource, $service . ' *'];
        $permissions = Permission::findByNames($names);
        if (!$actor['user']->isAdmin() && Auth::isResourceGuarded($resource)
            && ($permissions->isEmpty() || $actor['user']->doesntHavePermissions($permissions))) return false;
        $directives = Auth::getDirectivesForPermissions($names);
        $query = $model::withoutCache()->setEagerLoads([]);
        if ($directives->isNotEmpty()) {
            // Group even OR directives so they cannot escape tenant/record
            // predicates. The real core-auth middleware supplies self/session.
            $query->where(function ($nested) use ($directives) {
                foreach ($directives as $directive) $directive->apply($nested);
            });
        }
        return $query->where('company_uuid', $actor['company']->uuid)->where('uuid', $uuid)->exists();
    }
}
