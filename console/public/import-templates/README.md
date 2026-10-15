# Fleet import template

This self-hosted XLSX replaces the missing upstream Fleet template. The first
row must contain Name. The pinned Fleet importer supports name/fleet/fleet_name
only and assigns status active in the current company. Do not add notes or
sample rows: every nonempty row creates a Fleet. Driver/vehicle/service-area
assignment is handled after import. The template contains no demo data.

Schema: fleetbase/fleetops commit 84dcc15cdc1c1169a6cc8ec7840d1a34074fa5b0,
server/src/Models/Fleet.php::createFromImport and Imports/FleetImport.php.
