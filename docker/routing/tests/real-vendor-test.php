<?php

require '/fleetbase/api/vendor/autoload.php';

use Fleetbase\FleetOps\Support\OSRM;

$points = OSRM::decodePolyline('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
if (abs($points[0]->getLat() - 38.5) > 1e-8 || abs($points[0]->getLng() + 120.2) > 1e-8) {
    throw new RuntimeException('Real vendor Point/Polyline integration failed');
}

$method = new ReflectionMethod(OSRM::class, 'decodeRouteGeometry');
$points = $method->invoke(null, ['type' => 'LineString', 'coordinates' => [[109.3, 0], [109.34, -0.03]]], 'geojson');
if ($points[0]->getLat() !== 0.0 || $points[0]->getLng() !== 109.3) {
    throw new RuntimeException('Real vendor GeoJSON Point integration failed');
}

echo json_encode(['status' => 'passed', 'decoder' => 'actual production vendor classes', 'network' => false, 'database' => false]), PHP_EOL;
