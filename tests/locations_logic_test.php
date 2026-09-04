<?php
require_once __DIR__ . '/../lib/locations.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: PDO SQLite is not available.\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL)');
ensure_location_schema($pdo);

$count = (int)$pdo->query('SELECT COUNT(*) FROM epss_location')->fetchColumn();
$hq = (int)$pdo->query("SELECT COUNT(*) FROM epss_location WHERE location_type='hq'")->fetchColumn();
$hubs = (int)$pdo->query("SELECT COUNT(*) FROM epss_location WHERE location_type='hub'")->fetchColumn();
$aa = (int)$pdo->query("SELECT COUNT(*) FROM epss_location WHERE name IN ('Addis Ababa Hub 1','Addis Ababa Hub 2')")->fetchColumn();
$estimated = (int)$pdo->query("SELECT COUNT(*) FROM epss_location WHERE location_type='hub' AND verification_status='estimated'")->fetchColumn();
$hqRow = $pdo->query("SELECT latitude,longitude,verification_status FROM epss_location WHERE location_code='HQ'")->fetch(PDO::FETCH_ASSOC);

if ($count !== 20 || $hq !== 1 || $hubs !== 19 || $aa !== 2 || $estimated !== 19) {
    fwrite(STDERR, "Location seed structure is invalid.\n");
    exit(1);
}
if (!$hqRow || $hqRow['latitude'] !== null || $hqRow['longitude'] !== null || $hqRow['verification_status'] !== 'unverified') {
    fwrite(STDERR, "HQ must remain unpinned and unverified until coordinates are confirmed.\n");
    exit(1);
}
$columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('location_id', $columns, true)) {
    fwrite(STDERR, "users.location_id was not added.\n");
    exit(1);
}
ensure_location_schema($pdo);
if ((int)$pdo->query('SELECT COUNT(*) FROM epss_location')->fetchColumn() !== 20) {
    fwrite(STDERR, "Location seeding must be idempotent.\n");
    exit(1);
}
fwrite(STDOUT, "PASS: location schema and seed logic.\n");
