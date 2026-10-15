# Consumable Place API input

The build patch requires the exact original SHA-256 for PlaceController,
CreatePlaceRequest and ResolvablePoint. It preserves the pinned vendor source in
its public reference and fails closed when that source changes.

Coordinate-only creation no longer calls a reverse-geocoding provider. It keeps
the supplied point, allows an absent street, and uses a coordinate label when a
name is absent. Equal labels at different coordinate-only locations create
separate records. An address that cannot be geocoded returns 422 instead of a
500 or a newly invented 0,0 point. Explicit valid 0,0 input remains a coordinate.

String/address lengths and types, owner references, metadata, and global
latitude/longitude bounds are validated before controller conversion. Raw
malformed GeoJSON/coordinate pairs are rejected before the generic resolver can
cast them to 0,0. Update requests inherit the same rules. API search/geocoding
provider availability and private-file delivery are separate concerns.

The synthetic HTTP contract runs with no external network/provider access and
checks persistence, two equal labels, malformed input, bounds, update validation
and geocoder failure. It does not claim all point representations or all
upstream place import/update flows have been tested.
