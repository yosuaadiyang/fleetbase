<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\{Driver, FuelReport, Issue, Vehicle};
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, RateLimiter, Validator};

/** Navigator reports never accept ownership, assignment, approval or provider metadata. */
final class DriverReports
{
    private const FUEL_EDITABLE = ['draft', 'revised'];
    private const FUEL_INPUT = ['draft', 'revised', 'submitted', 'archived', 'canceled'];
    private const TYPES = ['vehicle', 'driver', 'route', 'payload_cargo', 'software_technical', 'operational', 'customer', 'security', 'environmental_sustainability'];
    private const PRIORITIES = ['low', 'medium', 'high', 'critical', 'scheduled_maintenance', 'operational_suggestion'];
    private const ISSUE_STATUSES = ['pending', 'in_progress', 'backlogged', 'requires_update', 'in_review', 're_opened', 'duplicate', 'pending_review', 'escalated', 'completed', 'canceled'];

    public static function handle(Request $request, Driver $driver, User $user, string $action, bool $fuel): JsonResponse
    {
        return DB::transaction(fn () => self::process($request, $driver, $user, $action, $fuel));
    }

    private static function process(Request $request, Driver $driver, User $user, string $action, bool $fuel): JsonResponse
    {
        if (!OrganizationChat::members($driver->company_uuid)->where('uuid', $user->uuid)->exists()) return self::denied();
        if (!in_array($action, ['query', 'find', 'create', 'update', 'delete'], true)) return self::denied();
        $fields = $fuel ? ['status', 'odometer', 'volume', 'metric_unit', 'amount', 'currency', 'location'] : ['type', 'category', 'report', 'priority', 'status', 'location'];
        $allowed = in_array($action, ['create', 'update'], true) ? [...$fields, 'driver'] : ($action === 'query' ? ['sort', 'driver'] : []);
        if (array_diff(array_keys($request->all()), $allowed)) return self::denied();
        if ($request->has('driver') && !in_array($request->input('driver'), [$driver->public_id, $driver->uuid], true)) return self::denied();
        $model = $fuel ? FuelReport::class : Issue::class;
        // Reporter's identity is checked too: dispatch-created/assigned reports are read only here.
        $query = $model::withoutCache()->where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)->where('reported_by_uuid', $user->uuid);
        if ($action === 'query') return self::reply($query->latest()->orderByDesc('uuid')->limit(100)->get()->map(fn ($r) => self::record($r, $fuel))->all());
        $record = null;
        if ($action !== 'create') {
            if (in_array($action, ['update', 'delete'], true)) $query->lockForUpdate();
            $id = $request->route()->parameter('id');
            $record = is_string($id) ? $query->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first() : null;
            if (!$record) return self::denied();
            if ($action === 'find') return self::reply(self::record($record, $fuel));
            // Driver cannot alter/delete a fuel expense after submission or staff review.
            if ($fuel && !in_array($record->status, self::FUEL_EDITABLE, true)) return self::denied();
        }
        if ($action === 'delete') {
            $id = $record->public_id;
            $record->delete();
            return self::reply(['id' => $id, 'deleted' => true]);
        }
        $data = $request->only($fields);
        foreach ($fuel ? ['status'] : ['type', 'priority', 'status'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) $data[$field] = strtolower(str_replace(['-', ' '], '_', trim($data[$field])));
        }
        $required = $action === 'create' ? 'required' : 'sometimes';
        $rules = $fuel ? [
            'odometer' => [$required, 'numeric', 'min:0', 'max:10000000'],
            'volume' => [$required, 'numeric', 'gt:0', 'max:1000000'],
            // Amount is in the currency's minor unit, matching Navigator MoneyInput.
            'amount' => [$required, 'integer', 'min:1', 'max:2000000000'],
            'currency' => [$required, 'string', 'regex:/^[A-Z]{3}$/'],
            'metric_unit' => [$required, 'string', 'in:L,l,mL,ml,cL,dL,gal,pt,qt'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', self::FUEL_INPUT)],
        ] : [
            'type' => [$required, 'string', 'in:' . implode(',', self::TYPES)],
            'category' => [$required, 'string', 'max:120'],
            'report' => [$required, 'string', 'max:10000'],
            'priority' => ['sometimes', 'string', 'in:' . implode(',', self::PRIORITIES)],
            'status' => ['sometimes', 'string', 'in:' . implode(',', self::ISSUE_STATUSES)],
        ];
        foreach ($fuel ? [] : ['category', 'report'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) $data[$field] = trim(strip_tags($data[$field]));
        }
        if (Validator::make($data, $rules)->fails()) return self::invalid();
        if ($action === 'create' && !isset($data['location'])) return self::reply(['errors' => ['A valid report location is required.'], 'code' => 'REPORT_LOCATION'], 422);
        if (array_key_exists('location', $data) && $data['location'] === null) return self::invalid();
        if (array_key_exists('location', $data) && $data['location'] !== null) {
            $location = $data['location'];
            // Only GeoJSON longitude/latitude is accepted, no arbitrary WKT/object casts.
            if (!is_array($location) || ($location['type'] ?? null) !== 'Point' || !is_array($location['coordinates'] ?? null)
                || !array_is_list($location['coordinates']) || count($location['coordinates']) !== 2) return self::invalid();
            [$lng, $lat] = $location['coordinates'];
            if (!is_numeric($lng) || !is_numeric($lat) || !is_finite((float) $lng) || !is_finite((float) $lat) || abs((float) $lng) > 180 || abs((float) $lat) > 90) return self::invalid();
            $data['location'] = new Point((float) $lat, (float) $lng);
        }
        $key = 'contrust:report:' . $driver->uuid;
        if (RateLimiter::tooManyAttempts($key, 30)) return self::reply(['errors' => ['Please wait before trying again.']], 429);
        RateLimiter::hit($key, 60);
        if ($action === 'create') {
            $record = new $model();
            $vehicle = Vehicle::withoutCache()->where('company_uuid', $driver->company_uuid)->where('uuid', $driver->vehicle_uuid)->value('uuid');
            $record->forceFill(['company_uuid' => $driver->company_uuid, 'driver_uuid' => $driver->uuid, 'reported_by_uuid' => $user->uuid, 'vehicle_uuid' => $vehicle,
                'status' => $fuel ? 'draft' : 'pending']);
        }
        $record->fill($data);
        $record->save();
        return self::reply(self::record($record->fresh(), $fuel), $action === 'create' ? 201 : 200);
    }

    private static function record($r, bool $fuel): array
    {
        $data = ['id' => $r->public_id, 'status' => $r->status, 'location' => $r->location, 'vehicle_name' => $r->vehicle_name,
            'created_at' => $r->created_at?->toISOString(), 'updated_at' => $r->updated_at?->toISOString()];
        foreach ($fuel ? ['odometer', 'volume', 'metric_unit', 'amount', 'currency'] : ['type', 'category', 'report', 'priority'] as $field) $data[$field] = $r->$field;
        return $data;
    }
    private static function denied(): JsonResponse { return self::reply(['errors' => ['You do not have permission to perform this action.']], 403); }
    private static function invalid(): JsonResponse { return self::reply(['errors' => ['Check the report fields and try again.']], 422); }
    private static function reply(array $data, int $status = 200): JsonResponse { return response()->json($data, $status)->header('Cache-Control', 'no-store, private'); }
}
