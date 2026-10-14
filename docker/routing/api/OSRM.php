<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Support\Encoding\Polyline;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class OSRM
 * A support class to interact with the Open Source Routing Machine (OSRM) API.
 */
class OSRM
{
    /**
     * The ORSM server API URL.
     *
     * @var string
     */
    protected static $baseUrl = 'https://router.project-osrm.org';

    /**
     * Get the route between two points.
     *
     * @param Point $start           starting point
     * @param Point $end             ending point
     * @param array $queryParameters additional query parameters
     *
     * @return array response from the OSRM API
     */
    public static function getRoute(Point $start, Point $end, array $queryParameters = [])
    {
        $coordinates = "{$start->getLng()},{$start->getLat()};{$end->getLng()},{$end->getLat()}";

        return static::getRouteFromCoordinatesString($coordinates, $queryParameters);
    }

    public static function getRouteFromPoints(array $points, array $queryParameters = [])
    {
        // Check if there are at least two points (start and end)
        if (count($points) < 2) {
            throw new \InvalidArgumentException('At least two points (start and end) are required.');
        }

        // Convert the array of Point objects into an OSRM-compatible string
        // Ensure all points are Point objects, not SpatialExpression
        $coordinates = array_map(function ($point) {
            // Convert to Point if it's a SpatialExpression or other type
            if (!$point instanceof Point) {
                $point = Utils::getPointFromMixed($point);
            }

            return "{$point->getLng()},{$point->getLat()}";
        }, $points);

        // Join the coordinates with a semicolon
        $coordinatesString = implode(';', $coordinates);

        // All route entry points share the provider/dataset-aware coordinate cache.
        return static::getRouteFromCoordinatesString($coordinatesString, $queryParameters);
    }

    public static function getRouteFromCoordinatesString(string $coordinates, array $queryParameters = [])
    {
        $cacheKey    = static::cacheKey('getRouteFromCoordinatesString', $coordinates, $queryParameters);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $url         = static::baseUrl() . "/route/v1/driving/{$coordinates}";
            $response    = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);
            $data        = $response->json();
            if (!is_array($data) || (!$response->successful() && ($data['code'] ?? null) === 'Ok')) {
                throw new \UnexpectedValueException('Invalid OSRM response');
            }

            // Check for the presence of the encoded polyline in each route and decode it if found
            if (isset($data['routes']) && is_array($data['routes'])) {
                foreach ($data['routes'] as &$route) {
                    if (isset($route['geometry'])) {
                        $route['waypoints'] = static::decodeRouteGeometry($route['geometry'], $queryParameters['geometries'] ?? 'polyline');
                    }
                }
            }

            // Store the result in the cache for 60 minutes
            static::cacheSuccessful($cacheKey, $data, $response->successful());

            return $data;
        } catch (\Throwable $e) {
            Log::warning('OSRM request timeout or error', ['error' => $e->getMessage(), 'coordinates' => $coordinates]);

            // Return empty response structure on error
            return ['code' => 'Error', 'routes' => []];
        }
    }

    /**
     * Get the nearest point on a road to a given location.
     *
     * @param Point $location        location point
     * @param array $queryParameters additional query parameters
     *
     * @return array response from the OSRM API
     */
    public static function getNearest(Point $location, array $queryParameters = [])
    {
        $cacheKey = static::cacheKey('getNearest', (string) $location, $queryParameters);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = "{$location->getLng()},{$location->getLat()}";
        $url         = static::baseUrl() . "/nearest/v1/driving/{$coordinates}";
        $response    = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);
        $result      = $response->json();

        static::cacheSuccessful($cacheKey, $result, $response->successful());

        return $result;
    }

    /**
     * Get a table of travel times or distances between multiple points.
     *
     * @param array $points          array of Point objects
     * @param array $queryParameters additional query parameters
     *
     * @return array response from the OSRM API
     */
    public static function getTable(array $points, array $queryParameters = [])
    {
        $cacheKey = static::cacheKey('getTable', serialize($points), $queryParameters);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = implode(';', array_map(function ($point) {
            if (!$point instanceof Point) {
                $point = Utils::getPointFromMixed($point);
            }

            return "{$point->getLng()},{$point->getLat()}";
        }, $points));

        $url      = static::baseUrl() . "/table/v1/driving/{$coordinates}";
        $response = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);
        $result   = $response->json();

        static::cacheSuccessful($cacheKey, $result, $response->successful());

        return $result;
    }

    /**
     * Get a trip between multiple points.
     *
     * @param array $points          array of Point objects
     * @param array $queryParameters additional query parameters
     *
     * @return array response from the OSRM API
     */
    public static function getTrip(array $points, array $queryParameters = [])
    {
        $cacheKey = static::cacheKey('getTrip', serialize($points), $queryParameters);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = implode(';', array_map(function ($point) {
            if (!$point instanceof Point) {
                $point = Utils::getPointFromMixed($point);
            }

            return "{$point->getLng()},{$point->getLat()}";
        }, $points));

        $url      = static::baseUrl() . "/trip/v1/driving/{$coordinates}";
        $response = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);
        $data     = $response->json();

        static::cacheSuccessful($cacheKey, $data, $response->successful());

        return $data;
    }

    /**
     * Get a match between GPS points and roads.
     *
     * @param array $points          array of Point objects
     * @param array $queryParameters additional query parameters
     *
     * @return array response from the OSRM API
     */
    public static function getMatch(array $points, array $queryParameters = [])
    {
        $coordinates = implode(';', array_map(function ($point) {
            if (!$point instanceof Point) {
                $point = Utils::getPointFromMixed($point);
            }

            return "{$point->getLng()},{$point->getLat()}";
        }, $points));
        $url = static::baseUrl() . "/match/v1/driving/{$coordinates}";

        $response = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);

        return $response->json();
    }

    /**
     * Get a tile for a specific zoom level and coordinates.
     *
     * @param int   $z               zoom level
     * @param int   $x               x coordinate
     * @param int   $y               y coordinate
     * @param array $queryParameters additional query parameters
     *
     * @return string response from the OSRM API
     */
    public static function getTile(int $z, int $x, int $y, array $queryParameters = [])
    {
        $url = static::baseUrl() . "/tile/v1/car/{$z}/{$x}/{$y}.mvt";

        $response = Http::timeout(static::timeoutSeconds())->connectTimeout(2)->get($url, $queryParameters);

        return $response->body();
    }

    /**
     * Decodes an encoded polyline string into an array of coordinates.
     *
     * @param string $polyline the encoded polyline string
     *
     * @return array an array of Point's
     */
    public static function decodePolyline($polyline, int $precision = 5)
    {
        if (!in_array($precision, [5, 6], true)) {
            throw new \InvalidArgumentException('Unsupported OSRM polyline precision.');
        }
        // The shared decoder constructs Points from longitude-first input.
        // OSRM uses Google's latitude-first encoding, so normalize its result.
        $scale = 10 ** ($precision - 5);

        return array_map(function (Point $point) use ($scale) {
            return new Point($point->getLng() / $scale, $point->getLat() / $scale);
        }, Polyline::decode($polyline));
    }

    protected static function decodeRouteGeometry($geometry, string $format): array
    {
        if (is_string($geometry)) {
            return static::decodePolyline($geometry, $format === 'polyline6' ? 6 : 5);
        }
        if (is_array($geometry) && ($geometry['type'] ?? null) === 'LineString' && is_array($geometry['coordinates'] ?? null)) {
            return array_map(function ($coordinate) {
                if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
                    throw new \UnexpectedValueException('Invalid OSRM GeoJSON coordinate.');
                }

                $latitude = (float) $coordinate[1];
                $longitude = (float) $coordinate[0];
                if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
                    throw new \UnexpectedValueException('OSRM coordinate is outside WGS84 bounds.');
                }

                return new Point($latitude, $longitude);
            }, $geometry['coordinates']);
        }

        throw new \UnexpectedValueException('Invalid OSRM geometry.');
    }

    protected static function cacheKey(string $method, string $input, array $parameters): string
    {
        $version = getenv('OSRM_DATA_VERSION') ?: 'default';

        return 'osrm:v2:' . $method . ':' . hash('sha256', static::baseUrl() . "\0" . $version . "\0" . $input . serialize($parameters));
    }

    protected static function cacheSuccessful(string $key, $data, bool $successful = true): void
    {
        if ($successful && is_array($data) && ($data['code'] ?? null) === 'Ok') {
            Cache::put($key, $data, 60 * 60);
        }
    }

    protected static function timeoutSeconds(): int
    {
        $value = filter_var(getenv('OSRM_TIMEOUT') ?: 5, FILTER_VALIDATE_INT);

        return $value !== false && $value >= 1 && $value <= 10 ? $value : 5;
    }

    protected static function baseUrl(): string
    {
        return rtrim(config('fleetops.osrm.host', static::$baseUrl), '/');
    }
}
