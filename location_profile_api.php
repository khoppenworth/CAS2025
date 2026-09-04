<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/locations.php';

auth_required();
refresh_current_user($pdo);
ensure_location_schema($pdo);

header('Content-Type: application/json; charset=utf-8');
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Authentication required.']);
    exit;
}

$locationPayload = static function (PDO $pdo, int $currentLocationId = 0): array {
    $records = location_records($pdo, false);
    $locations = [];
    foreach ($records as $row) {
        $id = (int)($row['id'] ?? 0);
        $active = (int)($row['is_active'] ?? 0) === 1;
        if (!$active && $id !== $currentLocationId) {
            continue;
        }
        $locations[] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? ''),
            'type' => (string)($row['location_type'] ?? ''),
            'region' => (string)($row['administrative_region'] ?? ''),
            'active' => $active,
        ];
    }
    return $locations;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $currentLocationId = (int)($user['location_id'] ?? 0);
    $response = [
        'ok' => true,
        'current_location_id' => $currentLocationId,
        'locations' => $locationPayload($pdo, $currentLocationId),
    ];

    if (isset($_GET['admin_users'])) {
        if ($userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Administrator access required.']);
            exit;
        }
        $rows = $pdo->query('SELECT id, location_id FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $users = [];
        $assignedLocationIds = [];
        foreach ($rows as $row) {
            $locationId = (int)($row['location_id'] ?? 0);
            if ($locationId > 0) {
                $assignedLocationIds[$locationId] = true;
            }
            $users[] = [
                'id' => (int)($row['id'] ?? 0),
                'location_id' => $locationId,
            ];
        }

        // Include inactive locations that are still assigned historically so User
        // Management can display the current value instead of "Unknown location".
        if ($assignedLocationIds) {
            $allLocations = location_records($pdo, false);
            $response['locations'] = [];
            foreach ($allLocations as $row) {
                $id = (int)($row['id'] ?? 0);
                $active = (int)($row['is_active'] ?? 0) === 1;
                if (!$active && !isset($assignedLocationIds[$id])) {
                    continue;
                }
                $response['locations'][] = [
                    'id' => $id,
                    'name' => (string)($row['name'] ?? ''),
                    'type' => (string)($row['location_type'] ?? ''),
                    'region' => (string)($row['administrative_region'] ?? ''),
                    'active' => $active,
                ];
            }
        }
        $response['users'] = $users;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
    exit;
}

csrf_check();
$targetUserId = max(0, (int)($_POST['user_id'] ?? $userId));
if ($targetUserId <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Select a valid user.']);
    exit;
}
if ($targetUserId !== $userId && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Administrator access required.']);
    exit;
}

$targetStmt = $pdo->prepare('SELECT id, location_id, username, full_name FROM users WHERE id = ? LIMIT 1');
$targetStmt->execute([$targetUserId]);
$targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'User not found.']);
    exit;
}

$locationId = max(0, (int)($_POST['location_id'] ?? 0));
if ($locationId > 0 && !location_is_active($pdo, $locationId)) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Select an active EPSS work location.']);
    exit;
}

$previousLocationId = (int)($targetUser['location_id'] ?? 0);
try {
    $pdo->prepare('UPDATE users SET location_id = ? WHERE id = ?')->execute([$locationId > 0 ? $locationId : null, $targetUserId]);
    try {
        $stmt = $pdo->prepare('INSERT INTO logs (user_id, action, meta) VALUES (?,?,?)');
        $stmt->execute([
            $targetUserId,
            $targetUserId === $userId ? 'profile_location_updated' : 'admin_user_location_updated',
            json_encode([
                'actor_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'from_location_id' => $previousLocationId > 0 ? $previousLocationId : null,
                'to_location_id' => $locationId > 0 ? $locationId : null,
            ], JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        error_log('User location audit log failed: ' . $e->getMessage());
    }
    if ($targetUserId === $userId) {
        refresh_current_user($pdo);
    }
    echo json_encode(['ok'=>true,'user_id'=>$targetUserId,'location_id'=>$locationId], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('User location update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Unable to save work location.']);
}
