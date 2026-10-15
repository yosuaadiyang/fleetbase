<?php

namespace App\Support;

/** Explicit public tracking contract. Never return whole internal resources. */
final class PublicOrderTrackingPayload
{
    public static function fromArray(array $order): array
    {
        $public = self::only($order, ['id', 'uuid', 'public_id', 'tracking', 'status', 'type', 'created_at', 'updated_at', 'has_driver_assigned']);
        $public['payload'] = self::payload($order['payload'] ?? null);
        $public['driver_assigned'] = self::driver($order['driver_assigned'] ?? null);
        $public['tracking_number'] = is_array($order['tracking_number'] ?? null)
            ? self::only($order['tracking_number'], ['id', 'uuid', 'public_id', 'tracking_number', 'barcode', 'qr_code']) : null;
        $public['tracking_statuses'] = self::collection($order['tracking_statuses'] ?? [], fn ($status) => self::only($status, ['id', 'uuid', 'public_id', 'status', 'code', 'status_code', 'details', 'created_at', 'updated_at']));
        $tracker = is_array($order['tracker_data'] ?? null) ? $order['tracker_data'] : [];
        $public['tracker_data'] = [
            'eta' => self::only((array) ($tracker['eta'] ?? []), ['active_stop_seconds', 'completion_seconds', 'active_stop_at', 'completion_at', 'start_seconds', 'start_at']),
            'progress' => self::only((array) ($tracker['progress'] ?? []), ['percentage', 'completed_stops', 'total_stops']),
            'active_stop' => self::stop($tracker['active_stop'] ?? null),
            'next_stop' => self::stop($tracker['next_stop'] ?? null),
            'driver' => is_array($tracker['driver'] ?? null) ? self::driver($tracker['driver']) : null,
        ];

        return $public;
    }

    private static function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_fill_keys($keys, true));
    }

    private static function collection($values, callable $project): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_map($project, array_filter($values, 'is_array')));
    }

    private static function geometry($value): ?array
    {
        return is_array($value) ? self::only($value, ['type', 'coordinates', 'bbox', 'latitude', 'longitude', 'lat', 'lng']) : null;
    }

    private static function place($place): ?array
    {
        if (!is_array($place)) {
            return null;
        }
        $result = self::only($place, ['id', 'uuid', 'public_id', 'name', 'address', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'country_name', 'tracking', 'status_code']);
        $result['location'] = self::geometry($place['location'] ?? null);

        return $result;
    }

    private static function payload($payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        $result = self::only($payload, ['id', 'uuid', 'public_id', 'type', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'waypoint_order']);
        foreach (['pickup', 'dropoff', 'return'] as $key) {
            $result[$key] = self::place($payload[$key] ?? null);
        }
        $result['waypoints'] = self::collection($payload['waypoints'] ?? [], fn ($place) => self::place($place));
        $result['entities'] = self::collection($payload['entities'] ?? [], fn ($entity) => self::only($entity, ['id', 'uuid', 'public_id', 'name', 'description', 'photo_url', 'tracking', 'destination_uuid', 'quantity']));

        return $result;
    }

    private static function stop($stop): ?array
    {
        return is_array($stop) ? self::only($stop, ['id', 'uuid', 'public_id', 'address', 'status', 'status_code']) : null;
    }

    private static function driver($driver): ?array
    {
        if (!is_array($driver)) {
            return null;
        }
        $result = self::only($driver, ['id', 'uuid', 'public_id', 'name', 'online', 'heading', 'avatar_url', 'vehicle_avatar']);
        foreach (['location', 'position'] as $key) {
            $result[$key] = self::geometry($driver[$key] ?? null);
        }

        return $result;
    }
}
