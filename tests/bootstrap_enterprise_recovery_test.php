<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$repoRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/cas-bootstrap-test-' . bin2hex(random_bytes(5));

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
};

$run = static function (array $command, array $env, string $cwd): array {
    $descriptors = [
        0 => ['file', '/dev/null', 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start bootstrap test process.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

try {
    mkdir($root . '/lib', 0755, true);
    mkdir($root . '/scripts', 0755, true);
    mkdir($root . '/storage/upgrades', 0755, true);

    $fixtureConfig = <<<'PHP'
<?php
declare(strict_types=1);

if (true) {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
}
PHP;
    file_put_contents($root . '/config.php', $fixtureConfig . "\n");
    file_put_contents($root . '/lib/upgrade.php', "<?php\nfunction legacy_upgrade_fixture(): string { return 'old-upgrade'; }\n");
    file_put_contents($root . '/lib/system_backup.php', "<?php\nfunction legacy_backup_fixture(): string { return 'old-backup'; }\n");
    file_put_contents($root . '/scripts/system_upgrade.php', "#!/usr/bin/env php\n<?php\necho 'old-updater';\n");

    chmod($root . '/config.php', 0640);
    chmod($root . '/lib/upgrade.php', 0644);
    chmod($root . '/lib/system_backup.php', 0640);
    chmod($root . '/scripts/system_upgrade.php', 0750);

    $before = [
        'config' => fileperms($root . '/config.php') & 0777,
        'upgrade' => fileperms($root . '/lib/upgrade.php') & 0777,
        'backup' => fileperms($root . '/lib/system_backup.php') & 0777,
        'script' => fileperms($root . '/scripts/system_upgrade.php') & 0777,
    ];

    $env = array_merge($_ENV, [
        'CAS_BOOTSTRAP_TEST' => '1',
        'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
    ]);
    $command = [
        PHP_BINARY ?: 'php',
        $repoRoot . '/scripts/bootstrap_enterprise_recovery.php',
        '--root=' . $root,
        '--source-dir=' . $repoRoot,
        '--prepare-only',
    ];

    $first = $run($command, $env, $repoRoot);
    $assert($first['exit'] === 0, 'First bootstrap failed: ' . $first['stderr']);
    $assert(str_contains($first['stdout'], 'Prepare-only bootstrap completed successfully.'), 'Bootstrap did not report prepare-only success.');

    $patchedConfig = (string)file_get_contents($root . '/config.php');
    $assert(str_contains($patchedConfig, 'CAS_ENTERPRISE_MAINTENANCE_GATE_BEGIN'), 'Maintenance gate was not installed.');
    $assert(str_contains($patchedConfig, 'CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER_BEGIN'), 'Active database pointer was not installed.');
    $assert(!is_file($root . '/storage/upgrades/maintenance.json'), 'Bootstrap maintenance gate was not cleared.');

    foreach ([
        'lib/enterprise_recovery.php',
        'lib/upgrade_jobs.php',
        'lib/system_backup.php',
        'lib/upgrade.php',
        'scripts/system_upgrade_worker.php',
        'scripts/system_upgrade.php',
    ] as $relative) {
        $assert(is_file($root . '/' . $relative), 'Bootstrap did not install ' . $relative);
        $assert(
            hash_file('sha256', $root . '/' . $relative) === hash_file('sha256', $repoRoot . '/' . $relative),
            'Bootstrap installed bytes differ from validated source for ' . $relative
        );
    }

    $assert((fileperms($root . '/config.php') & 0777) === $before['config'], 'config.php mode changed.');
    $assert((fileperms($root . '/lib/upgrade.php') & 0777) === $before['upgrade'], 'lib/upgrade.php mode changed.');
    $assert((fileperms($root . '/lib/system_backup.php') & 0777) === $before['backup'], 'lib/system_backup.php mode changed.');
    $assert((fileperms($root . '/scripts/system_upgrade.php') & 0777) === $before['script'], 'system_upgrade.php mode changed.');
    $assert((fileperms($root . '/lib/enterprise_recovery.php') & 0777) === $before['upgrade'], 'New lib file did not inherit lib metadata.');
    $assert((fileperms($root . '/scripts/system_upgrade_worker.php') & 0777) === $before['script'], 'New worker did not inherit script metadata.');

    // Idempotency: a second prepare run must not duplicate config hooks or change modes.
    $second = $run($command, $env, $repoRoot);
    $assert($second['exit'] === 0, 'Second bootstrap failed: ' . $second['stderr']);
    $patchedAgain = (string)file_get_contents($root . '/config.php');
    $assert(substr_count($patchedAgain, 'CAS_ENTERPRISE_MAINTENANCE_GATE_BEGIN') === 1, 'Maintenance hook duplicated on second bootstrap.');
    $assert(substr_count($patchedAgain, 'CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER_BEGIN') === 1, 'Database pointer hook duplicated on second bootstrap.');
    $assert((fileperms($root . '/config.php') & 0777) === $before['config'], 'config.php mode changed on second bootstrap.');

    $bootstrapCopies = glob($root . '/storage/upgrades/bootstrap/*/manifest.json') ?: [];
    $assert(count($bootstrapCopies) === 2, 'Each bootstrap run should retain an auditable pre-bootstrap manifest.');

    fwrite(STDOUT, "Enterprise bootstrap prepare/idempotency test passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Enterprise bootstrap test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $removeDirectory($root);
}
