<?php
declare(strict_types=1);

$source = realpath(__DIR__ . '/../lib/enterprise_runtime.php');
if ($source === false) {
    fwrite(STDERR, "enterprise_runtime.php not found.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . '/cas-enterprise-runtime-' . bin2hex(random_bytes(6));
mkdir($root . '/lib', 0755, true);
mkdir($root . '/storage/upgrades', 0755, true);
copy($source, $root . '/lib/enterprise_runtime.php');

try {
    // Simulate the persistent environment inherited by a reused PHP-FPM worker.
    putenv('DB_NAME=deployment_base_db');
    $_ENV['DB_NAME'] = 'deployment_base_env';

    file_put_contents(
        $root . '/storage/upgrades/active_database.json',
        json_encode(['db_name' => 'cas_candidate_verified'], JSON_PRETTY_PRINT) . "\n"
    );

    include $root . '/lib/enterprise_runtime.php';

    $assert(getenv('DB_NAME') === 'cas_candidate_verified',
        'Active DB pointer was not applied for the current request.');
    $assert(($_ENV['DB_NAME'] ?? null) === 'cas_candidate_verified',
        'Active DB pointer was not reflected in $_ENV.');
    $restore = $GLOBALS['CAS_ENTERPRISE_DB_ENV_RESTORE'] ?? null;
    $assert($restore instanceof Closure, 'Runtime did not expose its request cleanup closure.');

    // This is the same closure registered for request shutdown. Invoke it now to
    // model FPM request teardown, then prove the next request would see the base
    // environment rather than the candidate selected by the previous request.
    $restore();

    $assert(getenv('DB_NAME') === 'deployment_base_db',
        'Candidate DB leaked into the reused process environment after request cleanup.');
    $assert(($_ENV['DB_NAME'] ?? null) === 'deployment_base_env',
        'Candidate DB leaked into $_ENV after request cleanup.');

    @unlink($root . '/storage/upgrades/active_database.json');
    unset($GLOBALS['CAS_ENTERPRISE_DB_ENV_RESTORE']);

    include $root . '/lib/enterprise_runtime.php';

    $assert(getenv('DB_NAME') === 'deployment_base_db',
        'Pointer absence did not leave the base DB environment intact.');

    echo "Enterprise runtime request-scope regression passed.\n";
} finally {
    putenv('DB_NAME');
    unset($_ENV['DB_NAME'], $GLOBALS['CAS_ENTERPRISE_DB_ENV_RESTORE']);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($root);
}
