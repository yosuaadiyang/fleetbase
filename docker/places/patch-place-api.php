<?php
$root = $argv[1] ?? '/fleetbase/api/vendor/fleetbase/fleetops-api/server/src';
function replacePlace(string $source, string $before, string $after): string {
    $source = str_replace($before, $after, $source, $count);
    if ($count !== 1) throw new RuntimeException('Place patch must match exactly once.');
    return $source;
}
$files = [
    'Http/Controllers/Api/v1/PlaceController.php' => 'b2b312392fa5cb71d6e8a482868ad118b157ced55b85fdd48594388f26f696a6',
    'Http/Requests/CreatePlaceRequest.php' => '459b4eac2e4525ff4ccdb40144212bc73bf0ee922734d192dbb0cffc82228940',
    'Rules/ResolvablePoint.php' => 'c515ee5cb3d9ddad92ae64b3ca14e40b581772caa7f6fc744b2ee908383ad749',
];
foreach ($files as $relative => $hash) {
    $path = $root . '/' . $relative;
    if (hash_file('sha256', $path) !== $hash) throw new RuntimeException('Unexpected Place base artifact; review before upgrading.');
    $source = file_get_contents($path);
    if (str_ends_with($relative, 'PlaceController.php')) {
        $start = strpos($source, '        // if we have only latitude/longitude or location BUT no street1 do a reverse lookup');
        $end = strpos($source, '        // latitude / longitude', $start);
        if ($start === false || $end === false) throw new RuntimeException('Place coordinate boundary missing.');
        // Coordinate-only records do not depend on an external geocoder.
        $source = substr_replace($source, '', $start, $end - $start);
        $source = replacePlace($source, <<<'BEFORE'
        /** @var \Fleetbase\Models\Place */
        $place = $this->firstOrNewPlace([
            'company_uuid' => session('company'),
            'owner_uuid'   => data_get($input, 'owner_uuid'),
            'name'         => strtoupper($this->orValue($input, ['name', 'street1'])),
            'street1'      => strtoupper($input['street1']),
        ]);
BEFORE, <<<'AFTER'
        $coordinateOnly = ($input['location'] ?? null) instanceof Point && empty($input['street1']);
        if ($coordinateOnly && empty($input['name'])) {
            $input['name'] = sprintf('Location (%.6f, %.6f)', $input['location']->getLat(), $input['location']->getLng());
        }
        if (!$coordinateOnly && empty($input['street1'])) {
            return $this->apiError('Address lookup unavailable. Provide an address and coordinates.', 422);
        }
        // Coordinate-only records with the same label can represent different
        // points; never merge them using a missing street as an identity key.
        $place = $coordinateOnly ? new Place() : $this->firstOrNewPlace([
            'company_uuid' => session('company'),
            'owner_uuid' => data_get($input, 'owner_uuid'),
            'name' => strtoupper($this->orValue($input, ['name', 'street1'])),
            'street1' => strtoupper($input['street1']),
        ]);
AFTER);
        $source = replacePlace($source, <<<'BEFORE'
            } elseif (isset($place->location)) {
                $place->location = new Point(0, 0);
            }
BEFORE, <<<'AFTER'
            } else {
                return $this->apiError('Address lookup unavailable. Provide coordinates.', 422);
            }
AFTER);
        foreach ([
            'return Place::createFromGeocodingLookup($address);',
            'return Place::createFromReverseGeocodingLookup($point);',
            'return Geocoder::geocode($address)->get()->first();',
        ] as $call) {
            $body = $call;
            if ($call === 'return Place::createFromGeocodingLookup($address);') {
                $body = <<<'LOOKUP'
$place = Place::createFromGeocodingLookup($address);
            $point = $place?->location;
            return $point instanceof Point && ($point->getLat() != 0 || $point->getLng() != 0) ? $place : null;
LOOKUP;
            }
            $source = replacePlace($source, '        ' . $call, "        try {\n            " . $body . "\n        } catch (\\Throwable \$exception) {\n            return null;\n        }");
        }
    } elseif (str_ends_with($relative, 'CreatePlaceRequest.php')) {
        $source = replacePlace($source, "            'name'      => [", "            'name'      => ['nullable', 'string', 'max:255',");
        $source = replacePlace($source, "            'street1'   => [", "            'street1'   => ['nullable', 'string', 'max:255',");
        $source = replacePlace($source, "            'address'   => ['nullable'],", "            'address'   => ['nullable', 'string', 'max:1000'],");
        $source = replacePlace($source, "'customer'  => ['nullable', new ExistsInAny", "'customer'  => ['bail', 'nullable', 'string', new ExistsInAny");
        $source = replacePlace($source, "'contact'   => ['nullable', new ExistsInAny", "'contact'   => ['bail', 'nullable', 'string', new ExistsInAny");
        $source = replacePlace($source, "'vendor'    => 'nullable|exists:vendors,public_id'", "'vendor'    => 'bail|nullable|string|exists:vendors,public_id'");
        $source = replacePlace($source, "'latitude'  => ['nullable', 'required_with:longitude']", "'latitude'  => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90']");
        $source = replacePlace($source, "'longitude' => ['nullable', 'required_with:latitude']", "'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180']");
        $source = replacePlace($source, "        return [\n", <<<'AFTER'
        return [
            'owner' => ['nullable', function ($attribute, $value, $fail) {
                if ($this->isMethod('POST') && !is_string($value)) {
                    $fail('Owner must be a resource identifier.');
                    return;
                }
                $id = is_array($value) ? ($value['id'] ?? $value['customer_id'] ?? null) : $value;
                if (!is_string($id) || strlen($id) > 255 || $id === '') $fail('Owner must be a resource identifier.');
            }],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'security_access_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
AFTER . "\n");
    } else {
        $source = replacePlace($source, 'return $point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point;', <<<'AFTER'
return $point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point
                && is_finite((float) $point->getLat()) && abs($point->getLat()) <= 90
                && is_finite((float) $point->getLng()) && abs($point->getLng()) <= 180;
AFTER);
        $source = replacePlace($source, 'catch (\Exception $e)', 'catch (\Throwable $e)');
        $source = replacePlace($source, '        try {', <<<'SHAPE'
        // The generic resolver casts malformed pairs to 0,0; reject their raw
        // shape before it can erase that distinction.
        if (is_array($value)) {
            if (isset($value['type'])) {
                $geometry = $value['type'] === 'Feature' ? ($value['geometry'] ?? []) : $value;
                $pair = $geometry['coordinates'] ?? null;
                if (($geometry['type'] ?? null) !== 'Point' || !is_array($pair)
                    || !is_numeric($pair[0] ?? null) || !is_numeric($pair[1] ?? null)) return false;
            } elseif (array_is_list($value)) {
                if (!is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) return false;
            } else {
                foreach (['_lat', 'lat', '_latitude', 'latitude', 'x', 'lon', '_lon', 'long', 'lng', '_lng', '_longitude', 'longitude', 'y'] as $key) {
                    if (array_key_exists($key, $value) && !is_numeric($value[$key])) return false;
                }
            }
        }
        try {
SHAPE);

    }
    if (file_put_contents($path, $source) === false) throw new RuntimeException('Could not write reviewed Place patch.');
}
