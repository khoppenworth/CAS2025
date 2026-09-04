# EPSS Work Locations & GIS

CAS treats work location as a separate dimension from Directorate, Team and Work Role. This supports HQ-versus-hub and hub-to-hub analytics without changing questionnaire assignment rules.

## Location model

The feature adds an `epss_location` master table and a nullable `users.location_id` link. Administrators can add, edit, deactivate and reactivate locations from **Administration → Work Locations & GIS**. Old locations should be deactivated rather than deleted so historical links remain valid.

The initial master data contains 20 separate physical locations: 1 EPSS HQ and 19 hubs. Addis Ababa Hub 1 and Addis Ababa Hub 2 are independent locations with no parent grouping.

## GIS data quality

The 19 hub coordinates supplied in `epss_regional_hubs.csv` are seeded with `verification_status=estimated`. They must not be presented as verified facility coordinates until EPSS confirms them. EPSS HQ is seeded without latitude/longitude because an exact facility coordinate was not supplied.

Verification states are `unverified`, `estimated`, `verified_approximate`, and `verified_exact`.

## User-profile rollout

Work Location / Duty Station is added to the Profile page through the shared location UI layer and saved by the authenticated `location_profile_api.php` endpoint. It is intentionally not a profile-completion gate in the first rollout, so existing active users are not blocked immediately after deployment.

## GIS analytics

`admin/analytics_locations.php` provides an OpenStreetMap/Leaflet map and filters for assessment year, questionnaire, Directorate, Team, Work Role and Work Location. It reports assigned staff, staff assessed, actual average competency score, percentage reaching the 80% target, and GIS verification status. Average score and 80% target attainment are intentionally kept separate.

The map stores workplace coordinates only. CAS does not collect employee home coordinates or real-time GPS locations.

## Production migration

For an existing MariaDB/MySQL installation, run `migration_locations.sql`. Runtime location pages also call `ensure_location_schema()` so the new schema is created when first opened. The migration preserves existing user data and seeds only missing location codes.

Administrators can add future hubs or other location types without code changes. If a facility closes, mark it inactive rather than deleting it; administrative location changes are recorded in the location audit table.
