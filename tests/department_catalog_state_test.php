<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/department_teams.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE department_catalog (
    slug TEXT NOT NULL PRIMARY KEY,
    label TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec("INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES ('finance', 'Finance Operations', 99, CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, department TEXT, cadre TEXT)');

$catalog = department_catalog($pdo);
if (($catalog['finance']['label'] ?? '') !== 'Finance Operations') {
    fwrite(STDERR, "Existing built-in department labels should not be overwritten during bootstrap.\n");
    exit(1);
}

if (($catalog['finance']['archived_at'] ?? null) === null) {
    fwrite(STDERR, "Existing archived built-in departments should not be reactivated during bootstrap.\n");
    exit(1);
}

echo "Department catalog state tests passed.\n";
