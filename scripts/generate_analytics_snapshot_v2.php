<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

$questionnaireId = null;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $parsed = (int)$argv[1];
    if ($parsed > 0) {
        $questionnaireId = $parsed;
    }
}

$generatedBy = null;
if (isset($argv[2]) && is_numeric($argv[2])) {
    $parsedBy = (int)$argv[2];
    if ($parsedBy > 0) {
        $generatedBy = $parsedBy;
    }
}

$snapshot = analytics_snapshot_v2_generate($pdo, $questionnaireId, $generatedBy);
$snapshotId = isset($snapshot['snapshot_id']) ? (int)$snapshot['snapshot_id'] : 0;

echo "Generated analytics snapshot v2.\n";
if ($snapshotId > 0) {
    echo "Snapshot ID: {$snapshotId}\n";
}
echo "Summary: " . json_encode($snapshot['summary'] ?? [], JSON_PRETTY_PRINT) . "\n";
