<?php

function secure_links_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS secure_link_token (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            resource_type VARCHAR(64) NOT NULL,
            payload_json TEXT NULL,
            user_id INT NULL,
            single_use TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_secure_link_token_hash (token_hash),
            KEY idx_secure_link_resource_type (resource_type),
            KEY idx_secure_link_user_id (user_id),
            KEY idx_secure_link_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ensured = true;
}

function secure_links_generate_token(PDO $pdo, string $resourceType, array $payload, ?int $userId, int $ttlSeconds = 900, bool $singleUse = false, ?int $createdBy = null): string
{
    secure_links_ensure_schema($pdo);

    if ($ttlSeconds < 60) {
        $ttlSeconds = 60;
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttlSeconds . ' seconds');
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    $stmt = $pdo->prepare('INSERT INTO secure_link_token (token_hash, resource_type, payload_json, user_id, single_use, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tokenHash,
        $resourceType,
        $payloadJson,
        $userId,
        $singleUse ? 1 : 0,
        $createdBy,
        $expiresAt->format('Y-m-d H:i:s'),
    ]);

    return $token;
}

function secure_links_build_url(PDO $pdo, string $resourceType, array $payload, ?int $userId, int $ttlSeconds = 900, bool $singleUse = false, ?int $createdBy = null): string
{
    $token = secure_links_generate_token($pdo, $resourceType, $payload, $userId, $ttlSeconds, $singleUse, $createdBy);
    return url_for('download.php?t=' . rawurlencode($token));
}

function secure_links_resolve_token(PDO $pdo, string $rawToken): ?array
{
    secure_links_ensure_schema($pdo);

    $token = trim($rawToken);
    if ($token === '') {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9\-_]{20,128}$/', $token)) {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT * FROM secure_link_token WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $expiresAt = strtotime((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        return null;
    }

    $usedAt = trim((string)($row['used_at'] ?? ''));
    if ((int)($row['single_use'] ?? 0) === 1 && $usedAt !== '') {
        return null;
    }

    $payload = [];
    $rawPayload = (string)($row['payload_json'] ?? '');
    if ($rawPayload !== '') {
        $decoded = json_decode($rawPayload, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $row['payload'] = $payload;
    return $row;
}

function secure_links_mark_used(PDO $pdo, int $tokenId): void
{
    secure_links_ensure_schema($pdo);

    $stmt = $pdo->prepare('UPDATE secure_link_token SET used_at = NOW() WHERE id = ?');
    $stmt->execute([$tokenId]);
}

?>
