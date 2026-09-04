<?php

declare(strict_types=1);

/**
 * EPSS work-location master data and GIS helpers.
 *
 * Locations are intentionally independent from the Directorate / Team hierarchy.
 * The seed list is only an initial baseline; administrators can add, update,
 * deactivate and reactivate records without a code change.
 */

function location_type_options(): array
{
    return [
        'hq' => 'HQ',
        'hub' => 'Hub',
        'other' => 'Other',
    ];
}

function location_verification_options(): array
{
    return [
        'unverified' => 'Unverified',
        'estimated' => 'Estimated',
        'verified_approximate' => 'Verified – approximate compound',
        'verified_exact' => 'Verified – exact facility',
    ];
}

function location_seed_records(): array
{
    return [
        [
            'location_code' => 'HQ',
            'name' => 'EPSS HQ',
            'location_type' => 'hq',
            'administrative_region' => 'Addis Ababa City Administration',
            'physical_address' => 'Addis Ababa, Ethiopia',
            'latitude' => null,
            'longitude' => null,
            'verification_status' => 'unverified',
            'notes' => 'Separate EPSS headquarters location. Exact facility coordinates must be verified before mapping.',
        ],
        ['location_code'=>'HUB-AA1','name'=>'Addis Ababa Hub 1','location_type'=>'hub','administrative_region'=>'Addis Ababa City Administration','physical_address'=>'Swaziland Street (Central Depot)','latitude'=>9.0435,'longitude'=>38.7423,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-AA2','name'=>'Addis Ababa Hub 2','location_type'=>'hub','administrative_region'=>'Addis Ababa City Administration','physical_address'=>"Gulelle Area / St. Paul's Campus",'latitude'=>9.0440,'longitude'=>38.7415,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-ADAMA','name'=>'Adama Hub','location_type'=>'hub','administrative_region'=>'Oromia Region','physical_address'=>'Industrial & Logistics Zone, Adama','latitude'=>8.5414,'longitude'=>39.2689,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-ARBA-MINCH','name'=>'Arba Minch Hub','location_type'=>'hub','administrative_region'=>'South Ethiopia Region','physical_address'=>'Hospital Road Area, Arba Minch','latitude'=>6.0206,'longitude'=>37.5511,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-ASSOSA','name'=>'Assosa Hub','location_type'=>'hub','administrative_region'=>'Benishangul-Gumuz Region','physical_address'=>'General Logistics Sector, Assosa','latitude'=>10.0667,'longitude'=>34.5333,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-BAHIR-DAR','name'=>'Bahir Dar Hub','location_type'=>'hub','administrative_region'=>'Amhara Region','physical_address'=>'Kebele 11 Logistics Strip, Bahir Dar','latitude'=>11.5742,'longitude'=>37.3614,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-BALE-GOBA','name'=>'Bale Goba Hub','location_type'=>'hub','administrative_region'=>'Oromia Region','physical_address'=>'Hospital Corridor Zone, Goba','latitude'=>7.0101,'longitude'=>39.9793,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-DESSIE','name'=>'Dessie Hub','location_type'=>'hub','administrative_region'=>'Amhara Region','physical_address'=>'Combolcha Road Corridor, Dessie','latitude'=>11.1149,'longitude'=>39.6324,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-DIRE-DAWA','name'=>'Dire Dawa Hub','location_type'=>'hub','administrative_region'=>'Dire Dawa City Administration','physical_address'=>'Melka Jebdu Logistics Axis, Dire Dawa','latitude'=>9.5931,'longitude'=>41.8661,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-GAMBELLA','name'=>'Gambella Hub','location_type'=>'hub','administrative_region'=>'Gambella Region','physical_address'=>'Regional Hospital Zone, Gambella','latitude'=>8.2472,'longitude'=>34.5919,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-GONDAR','name'=>'Gondar Hub','location_type'=>'hub','administrative_region'=>'Amhara Region','physical_address'=>'Maraki Campus Area, Gondar','latitude'=>12.6074,'longitude'=>37.4582,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-HAWASSA','name'=>'Hawassa Hub','location_type'=>'hub','administrative_region'=>'Sidama Region / Central Ethiopia','physical_address'=>'Industrial Park Road, Hawassa','latitude'=>7.0470,'longitude'=>38.4752,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-JIJIGA','name'=>'Jijiga Hub','location_type'=>'hub','administrative_region'=>'Somali Region','physical_address'=>'Eastern Logistics Ring, Jijiga','latitude'=>9.3512,'longitude'=>42.7951,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-JIMMA','name'=>'Jimma Hub','location_type'=>'hub','administrative_region'=>'Oromia Region','physical_address'=>'JUMC Road District, Jimma','latitude'=>7.6734,'longitude'=>36.8344,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-MEKELLE','name'=>'Mekelle Hub','location_type'=>'hub','administrative_region'=>'Tigray Region','physical_address'=>'Ayder Health Sector, Mekelle','latitude'=>13.4967,'longitude'=>39.4683,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-NEKEMTE','name'=>'Nekemte Hub','location_type'=>'hub','administrative_region'=>'Oromia Region','physical_address'=>'Wollega Zone Logistics Strip, Nekemte','latitude'=>9.0833,'longitude'=>36.5500,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-SEMERA','name'=>'Semera Hub','location_type'=>'hub','administrative_region'=>'Afar Region','physical_address'=>'Logia-Semera Transit Road, Semera','latitude'=>11.7922,'longitude'=>41.0051,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-SHIRE','name'=>'Shire Hub','location_type'=>'hub','administrative_region'=>'Tigray Region','physical_address'=>'Northern Transport Sector, Indaselassie','latitude'=>14.1022,'longitude'=>38.2831,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
        ['location_code'=>'HUB-WOLAITA-SODO','name'=>'Wolaita Sodo Hub','location_type'=>'hub','administrative_region'=>'South Ethiopia Region','physical_address'=>'Sodo Core Logistics District','latitude'=>6.8575,'longitude'=>37.7608,'verification_status'=>'estimated','notes'=>'Initial coordinate imported from EPSS regional hubs seed CSV; verify exact facility location.'],
    ];
}

function ensure_location_schema(PDO $pdo): void
{
    static $ensured = [];
    $key = spl_object_id($pdo);
    if (isset($ensured[$key])) {
        return;
    }

    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS epss_location ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'location_code TEXT NOT NULL UNIQUE, '
            . 'name TEXT NOT NULL UNIQUE, '
            . 'location_type TEXT NOT NULL DEFAULT "hub", '
            . 'administrative_region TEXT NOT NULL, '
            . 'physical_address TEXT NULL, '
            . 'latitude REAL NULL, '
            . 'longitude REAL NULL, '
            . 'verification_status TEXT NOT NULL DEFAULT "unverified", '
            . 'is_active INTEGER NOT NULL DEFAULT 1, '
            . 'effective_from TEXT NULL, '
            . 'effective_to TEXT NULL, '
            . 'notes TEXT NULL, '
            . 'created_by INTEGER NULL, '
            . 'updated_by INTEGER NULL, '
            . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS epss_location_audit ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'location_id INTEGER NULL, '
            . 'action TEXT NOT NULL, '
            . 'before_json TEXT NULL, '
            . 'after_json TEXT NULL, '
            . 'changed_by INTEGER NULL, '
            . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );

        $userColumns = [];
        try {
            foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $userColumns[(string)($column['name'] ?? '')] = true;
            }
        } catch (Throwable $e) {
            $userColumns = [];
        }
        if ($userColumns !== [] && !isset($userColumns['location_id'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN location_id INTEGER NULL');
        }
        if ($userColumns !== []) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_location_id ON users(location_id)');
        }
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS epss_location ("
            . "id INT AUTO_INCREMENT PRIMARY KEY, "
            . "location_code VARCHAR(80) NOT NULL UNIQUE, "
            . "name VARCHAR(200) NOT NULL UNIQUE, "
            . "location_type VARCHAR(30) NOT NULL DEFAULT 'hub', "
            . "administrative_region VARCHAR(200) NOT NULL, "
            . "physical_address VARCHAR(500) NULL, "
            . "latitude DECIMAL(10,7) NULL, "
            . "longitude DECIMAL(10,7) NULL, "
            . "verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified', "
            . "is_active TINYINT(1) NOT NULL DEFAULT 1, "
            . "effective_from DATE NULL, "
            . "effective_to DATE NULL, "
            . "notes TEXT NULL, "
            . "created_by INT NULL, "
            . "updated_by INT NULL, "
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, "
            . "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, "
            . "KEY idx_epss_location_active (is_active), "
            . "KEY idx_epss_location_region (administrative_region)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS epss_location_audit ("
            . "id BIGINT AUTO_INCREMENT PRIMARY KEY, "
            . "location_id INT NULL, "
            . "action VARCHAR(40) NOT NULL, "
            . "before_json LONGTEXT NULL, "
            . "after_json LONGTEXT NULL, "
            . "changed_by INT NULL, "
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, "
            . "KEY idx_location_audit_location (location_id), "
            . "KEY idx_location_audit_created (created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'location_id'"
            );
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE users ADD COLUMN location_id INT NULL AFTER work_function');
            }
            $idx = $pdo->query(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_location_id'"
            );
            if ($idx && (int)$idx->fetchColumn() === 0) {
                $pdo->exec('CREATE INDEX idx_users_location_id ON users(location_id)');
            }
        } catch (Throwable $e) {
            error_log('ensure_location_schema users.location_id failed: ' . $e->getMessage());
        }
    }

    location_seed_defaults($pdo);
    $ensured[$key] = true;
}

function location_seed_defaults(PDO $pdo): void
{
    $check = $pdo->prepare('SELECT id FROM epss_location WHERE location_code = ? LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO epss_location '
        . '(location_code,name,location_type,administrative_region,physical_address,latitude,longitude,verification_status,is_active,notes) '
        . 'VALUES (?,?,?,?,?,?,?,?,1,?)'
    );
    foreach (location_seed_records() as $seed) {
        $check->execute([(string)$seed['location_code']]);
        if ($check->fetchColumn()) {
            continue;
        }
        $insert->execute([
            $seed['location_code'],
            $seed['name'],
            $seed['location_type'],
            $seed['administrative_region'],
            $seed['physical_address'],
            $seed['latitude'],
            $seed['longitude'],
            $seed['verification_status'],
            $seed['notes'],
        ]);
    }
}

function location_records(PDO $pdo, bool $activeOnly = false): array
{
    ensure_location_schema($pdo);
    $sql = 'SELECT * FROM epss_location';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= " ORDER BY CASE WHEN location_type='hq' THEN 0 ELSE 1 END, is_active DESC, name ASC";
    $stmt = $pdo->query($sql);
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function location_find(PDO $pdo, int $locationId): ?array
{
    if ($locationId <= 0) {
        return null;
    }
    ensure_location_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM epss_location WHERE id = ? LIMIT 1');
    $stmt->execute([$locationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function location_choices(PDO $pdo, bool $activeOnly = true): array
{
    $choices = [];
    foreach (location_records($pdo, $activeOnly) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $label = trim((string)($row['name'] ?? ''));
        if (!$activeOnly && (int)($row['is_active'] ?? 1) !== 1) {
            $label .= ' (inactive)';
        }
        $choices[$id] = $label;
    }
    return $choices;
}

function location_map_by_id(PDO $pdo, bool $activeOnly = false): array
{
    $map = [];
    foreach (location_records($pdo, $activeOnly) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $row;
        }
    }
    return $map;
}

function location_is_active(PDO $pdo, int $locationId): bool
{
    $row = location_find($pdo, $locationId);
    return $row !== null && (int)($row['is_active'] ?? 0) === 1;
}

function location_coordinate_value($value): ?float
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function location_validate_payload(array $payload): array
{
    $errors = [];
    $name = trim((string)($payload['name'] ?? ''));
    $region = trim((string)($payload['administrative_region'] ?? ''));
    $type = trim((string)($payload['location_type'] ?? ''));
    $verification = trim((string)($payload['verification_status'] ?? ''));
    $latRaw = $payload['latitude'] ?? null;
    $lonRaw = $payload['longitude'] ?? null;
    $lat = location_coordinate_value($latRaw);
    $lon = location_coordinate_value($lonRaw);
    $latProvided = $latRaw !== null && trim((string)$latRaw) !== '';
    $lonProvided = $lonRaw !== null && trim((string)$lonRaw) !== '';

    if ($name === '') {
        $errors[] = 'Location name is required.';
    }
    if ($region === '') {
        $errors[] = 'Administrative region is required.';
    }
    if (!array_key_exists($type, location_type_options())) {
        $errors[] = 'Select a valid location type.';
    }
    if (!array_key_exists($verification, location_verification_options())) {
        $errors[] = 'Select a valid GIS verification status.';
    }
    if ($latProvided !== $lonProvided) {
        $errors[] = 'Latitude and longitude must either both be provided or both be blank.';
    }
    if ($latProvided && ($lat === null || $lat < -90 || $lat > 90)) {
        $errors[] = 'Latitude must be a number between -90 and 90.';
    }
    if ($lonProvided && ($lon === null || $lon < -180 || $lon > 180)) {
        $errors[] = 'Longitude must be a number between -180 and 180.';
    }

    return $errors;
}

function location_generate_code(PDO $pdo, string $name, ?int $excludeId = null): string
{
    $stem = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    if ($stem === '') {
        $stem = 'LOCATION';
    }
    $base = 'LOC-' . substr($stem, 0, 55);
    $candidate = $base;
    $suffix = 2;
    while (true) {
        $sql = 'SELECT id FROM epss_location WHERE location_code = ?';
        $params = [$candidate];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

function location_audit(PDO $pdo, ?int $locationId, string $action, ?array $before, ?array $after, ?int $changedBy): void
{
    ensure_location_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO epss_location_audit (location_id,action,before_json,after_json,changed_by) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([
        $locationId,
        $action,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $changedBy,
    ]);
}
