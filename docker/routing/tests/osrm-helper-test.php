<?php
// Standalone HTTP/cache doubles: never bootstrap the app or connect to a database.
namespace Fleetbase\LaravelMysqlSpatial\Types {
    class Point {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat() { return $this->lat; }
        public function getLng() { return $this->lng; }
        public function __toString() { return $this->lng . ',' . $this->lat; }
    }
}
namespace Illuminate\Support\Facades {
    class Cache {
        public static array $values = [];
        public static function has($key) { return array_key_exists($key, self::$values); }
        public static function get($key) { return self::$values[$key]; }
        public static function put($key, $value, $ttl) { self::$values[$key] = $value; }
    }
    class Log { public static function warning($message, $context) {} }
    class Http {
        public static array $requests = [];
        public static mixed $next = ['code' => 'Ok', 'routes' => []];
        public static bool $ok = true;
        public static int $timeout;
        public static int $connectTimeout;
        public static function timeout($seconds) { self::$timeout = $seconds; return new self; }
        public function connectTimeout($seconds) { self::$connectTimeout = $seconds; return $this; }
        public function get($url, $parameters) {
            self::$requests[] = [$url, $parameters];
            if (self::$next instanceof \Throwable) { throw self::$next; }
            return new class(self::$next, self::$ok) {
                public function __construct(private mixed $data, private bool $ok) {}
                public function successful() { return $this->ok; }
                public function json() { return $this->data; }
            };
        }
    }
}
namespace {
    use Fleetbase\FleetOps\Support\OSRM;
    use Fleetbase\LaravelMysqlSpatial\Types\Point;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Http;
    $host = 'http://routing-one:5000';
    function config($name, $default = null) { global $host; return $name === 'fleetops.osrm.host' ? $host : $default; }
    require __DIR__ . '/fixtures/Polyline.php';
    require __DIR__ . '/../api/OSRM.php';
    $count = 0;
    function check($condition, $name) {
        global $count;
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $name); }
        echo 'PASS: ', $name, PHP_EOL;
        $count++;
    }
    function resetFixture() {
        Cache::$values = []; Http::$requests = []; Http::$next = ['code' => 'Ok', 'routes' => []]; Http::$ok = true;
        putenv('OSRM_TIMEOUT'); putenv('OSRM_DATA_VERSION');
    }
    resetFixture();
    OSRM::getRouteFromCoordinatesString('106.8,-6.1;106.9,-6.2');
    OSRM::getRouteFromCoordinatesString('106.8,-6.1;106.9,-6.2');
    check(count(Http::$requests) === 1, 'successful coordinate route reuses cache');
    check(Http::$timeout === 5 && Http::$connectTimeout === 2, 'bounded request/connect timeout defaults');
    $host = 'http://routing-two:5000';
    OSRM::getRouteFromCoordinatesString('106.8,-6.1;106.9,-6.2');
    check(count(Http::$requests) === 2, 'different provider cannot reuse the previous provider response');
    putenv('OSRM_DATA_VERSION=next-snapshot');
    OSRM::getRouteFromCoordinatesString('106.8,-6.1;106.9,-6.2');
    check(count(Http::$requests) === 3, 'dataset version invalidates route cache');
    resetFixture();
    Http::$next = new \RuntimeException('network timeout');
    $points = [new Point(0, 109.3), new Point(-0.03, 109.34)];
    check(OSRM::getRouteFromPoints($points)['code'] === 'Error', 'network failure retains route error contract');
    check(Cache::$values === [], 'point wrapper does not cache a network failure');
    Http::$next = ['code' => 'Ok', 'routes' => []];
    check(OSRM::getRouteFromPoints($points)['code'] === 'Ok' && count(Http::$requests) === 2, 'retry succeeds immediately after transient failure');
    resetFixture();
    Http::$next = ['code' => 'NoRoute', 'routes' => []];
    OSRM::getRouteFromCoordinatesString('1,1;2,2');
    check(Cache::$values === [], 'NoRoute is not cached for an hour');
    resetFixture();
    Http::$next = null;
    check(OSRM::getRouteFromCoordinatesString('1,1;2,2')['code'] === 'Error' && Cache::$values === [], 'malformed JSON returns an uncached error');
    resetFixture();
    Http::$next = ['code' => 'Ok', 'routes' => []]; Http::$ok = false;
    check(OSRM::getRouteFromCoordinatesString('1,1;2,2')['code'] === 'Error' && Cache::$values === [], 'HTTP failure is not accepted as successful');
    resetFixture();
    Http::$next = ['code' => 'Ok', 'routes' => [['geometry' => ['type' => 'LineString', 'coordinates' => [[109.3, 0], [109.34, -0.03]]]]]];
    $data = OSRM::getRouteFromCoordinatesString('109.3,0;109.34,-0.03', ['geometries' => 'geojson']);
    $first = $data['routes'][0]['waypoints'][0];
    check($first->getLat() === 0.0 && $first->getLng() === 109.3, 'GeoJSON longitude/latitude becomes a correctly ordered Point, preserving zero');
    resetFixture();
    Http::$next = ['code' => 'Ok', 'routes' => [['geometry' => ['type' => 'LineString', 'coordinates' => [[109.3, 999]]]]]];
    check(OSRM::getRouteFromCoordinatesString('1,1;2,2', ['geometries' => 'geojson'])['code'] === 'Error' && Cache::$values === [], 'invalid geometry cannot poison the cache');
    $polyline = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';
    $decoded = OSRM::decodePolyline($polyline);
    check(abs($decoded[0]->getLat() - 38.5) < 1e-8 && abs($decoded[0]->getLng() + 120.2) < 1e-8, 'Google reference polyline decodes to latitude 38.5 / longitude -120.2');
    $encoded6 = \Fleetbase\FleetOps\Support\Encoding\Polyline::encode([[-61.754, 1068.272], [-62.088, 1068.456]]);
    $decoded6 = OSRM::decodePolyline($encoded6, 6);
    check(abs($decoded6[0]->getLat() + 6.1754) < 1e-8 && abs($decoded6[0]->getLng() - 106.8272) < 1e-8, 'polyline6 uses the correct precision');
    resetFixture();
    putenv('OSRM_TIMEOUT=99'); OSRM::getRouteFromCoordinatesString('1,1;2,2');
    check(Http::$timeout === 5, 'out-of-range timeout uses a safe default');
    echo $count, ' checks passed; no network or database requests', PHP_EOL;
}
